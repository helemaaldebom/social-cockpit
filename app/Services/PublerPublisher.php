<?php

namespace App\Services;

use App\Contracts\PublisherInterface;
use App\Models\Channel;
use App\Models\ContentItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublerPublisher implements PublisherInterface
{
    private const BASE_URL = 'https://app.publer.com/api/v1';

    private string $apiKey;
    private string $workspaceId;

    public function __construct()
    {
        $this->apiKey = config('services.publer.api_key');
        $this->workspaceId = config('services.publer.workspace_id');
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer-API ' . $this->apiKey,
            'Publer-Workspace-Id' => $this->workspaceId,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Map our SocialNetwork enum value to Publer's network key.
     */
    private function publerNetwork(string $network): string
    {
        return match ($network) {
            'linkedin'  => 'linkedin',
            'facebook'  => 'facebook',
            'instagram' => 'instagram',
            default     => $network,
        };
    }

    /**
     * Upload a local file to Publer and return its media ID.
     */
    private function uploadMedia(string $localPath): ?string
    {
        if (! file_exists($localPath)) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization'       => 'Bearer-API ' . $this->apiKey,
            'Publer-Workspace-Id' => $this->workspaceId,
            'Accept'              => 'application/json',
        ])->attach('file', file_get_contents($localPath), basename($localPath))
          ->post(self::BASE_URL . '/media');

        if (! $response->successful()) {
            Log::warning('Publer media upload failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'path'   => $localPath,
            ]);
            return null;
        }

        return $response->json('id');
    }

    public function schedulePost(ContentItem $item, array $publerAccountIds, CarbonInterface $scheduledFor): string
    {
        // Upload alle media (foto + video). De aanwezigheid van video bepaalt het posttype.
        $mediaPaths = $item->allMediaPaths();
        $mediaItems = [];
        $hasVideo   = false;

        foreach ($mediaPaths as $relativePath) {
            $localPath = storage_path('app/public/' . $relativePath);
            $mediaId   = $this->uploadMedia($localPath);
            if (! $mediaId) {
                continue;
            }
            $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4', 'mov', 'avi', 'wmv', 'webm', 'm4v'], true)) {
                $hasVideo = true;
            }
            $mediaItems[] = ['id' => $mediaId];
        }

        $type = match (true) {
            $hasVideo            => 'video',
            ! empty($mediaItems) => 'photo',
            default              => 'status',
        };

        // Build one post-entry per channel so each gets the right network type
        $channels = $item->channels->filter(
            fn (Channel $ch) => in_array($ch->publer_account_id, $publerAccountIds)
        );

        $posts = $channels->map(function (Channel $channel) use ($item, $scheduledFor, $mediaItems, $type) {
            $network = $channel->network instanceof \App\Enums\SocialNetwork
                ? $channel->network->value
                : (string) $channel->network;

            $publerNet = $this->publerNetwork($network);

            $networkPayload = [
                'type' => $type,
                'text' => $item->generated_text,
            ];

            if (! empty($mediaItems)) {
                $networkPayload['media'] = $mediaItems;
            }

            return [
                'networks' => [
                    $publerNet => $networkPayload,
                ],
                'accounts' => [
                    [
                        'id'           => $channel->publer_account_id,
                        'scheduled_at' => $scheduledFor->toIso8601String(),
                    ],
                ],
            ];
        })->values()->toArray();

        $payload = [
            'bulk' => [
                'state' => 'scheduled',
                'posts' => $posts,
            ],
        ];

        $response = Http::withHeaders($this->headers())
            ->post(self::BASE_URL . '/posts/schedule', $payload);

        if (! $response->successful()) {
            Log::error('Publer schedulePost failed', [
                'status'          => $response->status(),
                'body'            => $response->body(),
                'content_item_id' => $item->id,
            ]);
            throw new \RuntimeException('Publer API error: ' . $response->body());
        }

        // Schedule-call levert direct een job_id. De échte post_ids per netwerk
        // worden in een aparte job opgehaald (ResolvePublerPostIdsJob), zodat
        // deze worker-job snel afrondt en de queue niet blokkeert.
        $jobId = $response->json('job_id') ?? $response->json('data.id') ?? 'unknown';

        return (string) $jobId;
    }

    /**
     * Publieke wrapper voor ResolvePublerPostIdsJob. Geen interne sleep —
     * dat regelt de aanroepende job met dispatch->delay() of een eigen retry.
     */
    public function resolvePostIdsPublic(array $accountIds, CarbonInterface $scheduledFor, int $maxAttempts = 10, int $sleepMs = 1500): array
    {
        return $this->resolvePostIds($accountIds, $scheduledFor, $maxAttempts, $sleepMs);
    }

    /**
     * Haalt de scheduled posts op en filtert client-side op de specifieke
     * combinatie account_id ∈ $accountIds én scheduled_at == $scheduledFor.
     *
     * Dit is uniek per scheduling-call: voor één tijdstip kan er per
     * Publer-account maar één post staan. Daarmee voorkomen we dat we per
     * ongeluk andere posts in de workspace matchen.
     */
    private function resolvePostIds(array $accountIds, CarbonInterface $scheduledFor, int $maxAttempts = 10, int $sleepMs = 1500): array
    {
        $expectedTs = $scheduledFor->copy()->utc()->getTimestamp();
        $expected   = count($accountIds);

        $matched = [];

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            // Geen state-filter: tijdens verwerking kan een post kort in een
            // andere status zitten. We filteren in PHP op state == scheduled.
            $response = Http::withHeaders($this->headers())
                ->get(self::BASE_URL . '/posts');

            if (! $response->successful()) {
                Log::warning('Publer posts list failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                usleep($sleepMs * 1000);
                continue;
            }

            $body  = $response->json() ?? [];
            $posts = $body['posts'] ?? $body['data'] ?? [];

            $matched = [];
            foreach ((array) $posts as $post) {
                $accountId   = $post['account_id'] ?? null;
                $scheduledAt = $post['scheduled_at'] ?? null;
                $state       = strtolower((string) ($post['state'] ?? ''));

                if (! $accountId || ! $scheduledAt || $state !== 'scheduled') {
                    continue;
                }

                if (! in_array($accountId, $accountIds, true)) {
                    continue;
                }

                // Vergelijk in UTC seconds, immuun voor timezone-string-variaties.
                try {
                    $postTs = Carbon::parse($scheduledAt)->utc()->getTimestamp();
                } catch (\Throwable) {
                    continue;
                }

                if ($postTs !== $expectedTs) {
                    continue;
                }

                $matched[] = (string) ($post['id'] ?? '');
            }

            $matched = array_values(array_unique(array_filter($matched)));

            if (count($matched) >= $expected) {
                return $matched;
            }

            usleep($sleepMs * 1000);
        }

        Log::warning('Publer resolvePostIds onvolledig', [
            'expected_accounts' => $expected,
            'matched'           => count($matched),
            'scheduled_for'     => $scheduledFor->toIso8601String(),
        ]);
        return $matched;
    }

    /**
     * @deprecated Vervangen door resolvePostIds() — Publer biedt geen werkende job-endpoint.
     */
    private function resolvePostIdsForJob(string $jobId, int $maxAttempts = 12, int $sleepMs = 1000): array
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = Http::withHeaders($this->headers())
                ->get(self::BASE_URL . '/posts/jobs/' . $jobId);

            if (! $response->successful()) {
                Log::warning('Publer jobs poll failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'job_id' => $jobId,
                ]);
                usleep($sleepMs * 1000);
                continue;
            }

            $body   = $response->json() ?? [];
            $status = strtolower((string) ($body['status'] ?? ''));

            // Publer reageert met status complete/completed/done en/of success met posts erin.
            $posts = $body['payload'] ?? $body['posts'] ?? $body['data'] ?? [];
            $ids   = [];

            foreach ((array) $posts as $post) {
                $id = $post['id'] ?? $post['_id'] ?? null;
                if ($id) {
                    $ids[] = (string) $id;
                }
            }

            if (! empty($ids) && in_array($status, ['', 'complete', 'completed', 'done', 'success', 'finished'], true)) {
                return $ids;
            }

            if ($status === 'failed' || $status === 'error') {
                Log::error('Publer schedule job rapporteert failed', ['job_id' => $jobId, 'body' => $body]);
                return [];
            }

            usleep($sleepMs * 1000);
        }

        Log::warning('Publer post_ids resolven niet afgerond binnen timeout', ['job_id' => $jobId]);
        return [];
    }

    public function updatePost(string $publerPostId, ContentItem $item): void
    {
        // Update álle post-IDs die we kennen voor dit item (één per netwerk).
        $postIds = $item->publer_post_ids ?: [$publerPostId];
        $errors  = [];

        foreach ($postIds as $id) {
            $response = Http::withHeaders($this->headers())
                ->put(self::BASE_URL . '/posts/' . $id, [
                    'text' => $item->generated_text,
                ]);

            if (! $response->successful()) {
                Log::error('Publer updatePost failed', [
                    'status'         => $response->status(),
                    'body'           => $response->body(),
                    'publer_post_id' => $id,
                ]);
                $errors[] = "{$id}: " . $response->body();
            }
        }

        if (! empty($errors)) {
            throw new \RuntimeException('Publer API error(s): ' . implode(' | ', $errors));
        }
    }

    public function deletePost(string $publerPostId): void
    {
        $response = Http::withHeaders($this->headers())
            ->delete(self::BASE_URL . '/posts/' . $publerPostId);

        if (! $response->successful()) {
            Log::error('Publer deletePost failed', [
                'status'         => $response->status(),
                'body'           => $response->body(),
                'publer_post_id' => $publerPostId,
            ]);
            throw new \RuntimeException('Publer API error: ' . $response->body());
        }
    }

    public function getAccounts(): array
    {
        $response = Http::withHeaders($this->headers())
            ->get(self::BASE_URL . '/accounts');

        if (! $response->successful()) {
            throw new \RuntimeException('Publer API error: ' . $response->body());
        }

        $body = $response->json();
        return is_array($body) ? $body : ($body['data'] ?? []);
    }
}
