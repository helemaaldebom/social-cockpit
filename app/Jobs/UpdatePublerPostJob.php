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
use Illuminate\Support\Facades\Log;

class UpdatePublerPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly ContentItem $contentItem) {}

    public function handle(PublisherInterface $publisher): void
    {
        $item = $this->contentItem->fresh();

        if (! $item->publer_post_id) {
            return;
        }

        $publisher->updatePost($item->publer_post_id, $item);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UpdatePublerPostJob mislukt', [
            'content_item_id' => $this->contentItem->id,
            'error' => $exception->getMessage(),
        ]);

        app(TelegramService::class)->notify(
            "❌ Publer-update mislukt voor content item #{$this->contentItem->id}.\n{$exception->getMessage()}"
        );
    }
}
