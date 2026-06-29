<?php

namespace App\Services;

use App\Contracts\PublisherInterface;
use App\Models\Channel;
use App\Models\ContentItem;
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

        // API returns {"job_id": "..."} — we store that as our reference
        $jobId = $response->json('job_id') ?? $response->json('data.id') ?? 'unknown';

        return (string) $jobId;
    }

    public function updatePost(string $publerPostId, ContentItem $item): void
    {
        $response = Http::withHeaders($this->headers())
            ->put(self::BASE_URL . '/posts/' . $publerPostId, [
                'text' => $item->generated_text,
            ]);

        if (! $response->successful()) {
            Log::error('Publer updatePost failed', [
                'status'        => $response->status(),
                'body'          => $response->body(),
                'publer_post_id' => $publerPostId,
            ]);
            throw new \RuntimeException('Publer API error: ' . $response->body());
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
