<?php

namespace App\Jobs;

use App\Contracts\PublisherInterface;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $errors = [];

        foreach ($this->publerPostIds as $id) {
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

    public function failed(\Throwable $exception): void
    {
        Log::error('DeletePublerPostsJob mislukt', [
            'content_item_id' => $this->contentItemId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
