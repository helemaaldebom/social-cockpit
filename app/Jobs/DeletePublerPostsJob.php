<?php

namespace App\Jobs;

use App\Contracts\PublisherInterface;
use App\Models\ContentItem;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verwijdert één of meerdere Publer-posts asynchroon. We krijgen de post-IDs
 * direct mee (niet via een ContentItem) zodat de job ook werkt nadat het
 * ContentItem is (soft-)deleted.
 *
 * Fouten per post worden gelogd maar stoppen de loop niet — als één post in
 * Publer al weg/gepubliceerd is, willen we de rest alsnog opruimen.
 */
class DeletePublerPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly array $publerPostIds,
        public readonly int $contentItemId
    ) {}

    public function handle(PublisherInterface $publisher): void
    {
        // HARDE GUARD: bepaal voor welke Publer-accounts dit content_item
        // (en dus DEZE klant) bevoegd is. We verifieren elk post-ID via GET
        // tegen die accounts vóór we deleten — zo kan een fout-opgeslagen
        // post-ID NOOIT een post van een andere klant verwijderen.
        $allowedAccountIds = $this->allowedAccountIdsForItem();

        if (empty($allowedAccountIds)) {
            Log::warning('DeletePublerPostsJob: geen toegestane account_ids, alles overgeslagen', [
                'content_item_id' => $this->contentItemId,
            ]);
            return;
        }

        $errors = [];

        foreach ($this->publerPostIds as $id) {
            if (! $this->postBelongsToItem((string) $id, $allowedAccountIds)) {
                Log::warning('DeletePublerPostsJob geweigerd: post hoort niet bij content_item', [
                    'content_item_id' => $this->contentItemId,
                    'publer_post_id'  => $id,
                ]);
                continue;
            }

            try {
                $publisher->deletePost((string) $id);
                Log::info('DeletePublerPostsJob: post verwijderd', [
                    'publer_post_id'  => $id,
                    'content_item_id' => $this->contentItemId,
                ]);
            } catch (\Throwable $e) {
                Log::warning('DeletePublerPostsJob: kon post niet verwijderen', [
                    'publer_post_id'  => $id,
                    'content_item_id' => $this->contentItemId,
                    'error'           => $e->getMessage(),
                ]);
                $errors[] = "{$id}: " . $e->getMessage();
            }
        }

        if (! empty($errors)) {
            app(TelegramService::class)->notify(
                "⚠️ Niet alle Publer-posts konden verwijderd worden voor content item #{$this->contentItemId}.\n"
                . implode("\n", $errors)
            );
        }
    }

    private function allowedAccountIdsForItem(): array
    {
        $item = ContentItem::withTrashed()->find($this->contentItemId);
        if (! $item || ! $item->client) {
            return [];
        }
        return $item->client->channels()->whereNotNull('publer_account_id')->pluck('publer_account_id')->all();
    }

    private function postBelongsToItem(string $publerPostId, array $allowedAccountIds): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization'       => 'Bearer-API ' . config('services.publer.api_key'),
                'Publer-Workspace-Id' => config('services.publer.workspace_id'),
                'Accept'              => 'application/json',
            ])->get('https://app.publer.com/api/v1/posts/' . urlencode($publerPostId));
        } catch (\Throwable $e) {
            return false;
        }

        if (! $response->successful()) {
            // Bestaat niet meer of geen rechten — refuseer veilig (skip).
            return false;
        }

        $accountId = (string) ($response->json('account_id') ?? '');
        return $accountId !== '' && in_array($accountId, $allowedAccountIds, true);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DeletePublerPostsJob mislukt', [
            'content_item_id' => $this->contentItemId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
