<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Services\PublerPublisher;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Haalt na een bulk-schedule de échte post-IDs op uit Publer en zet ze
 * op het ContentItem. Loopt los van SchedulePostToPublerJob zodat die job
 * snel afrondt en de queue niet blokkeert.
 *
 * Bij geen volledige set IDs nog niet gevonden: opnieuw inplannen met
 * exponentiële backoff (max 5 keer ~5 min totaal). Daarna geven we op.
 */
class ResolvePublerPostIdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $contentItemId,
        public readonly array $publerAccountIds,
        public readonly string $scheduledForIso,
        public readonly int $attempt = 1
    ) {}

    public function handle(PublerPublisher $publisher): void
    {
        $item = ContentItem::find($this->contentItemId);
        if (! $item) {
            return;
        }

        // Korte poll-window: 10 × 1.5s = 15s. We zijn nu een aparte job, dus
        // gewoon nog een keer dispatchen als we niet alles vinden.
        $scheduledFor = Carbon::parse($this->scheduledForIso);
        $ids = $publisher->resolvePostIdsPublic($this->publerAccountIds, $scheduledFor, 10, 1500);

        if (count($ids) >= count($this->publerAccountIds)) {
            $item->publer_post_ids = $ids;
            $item->publer_post_id  = $ids[0];
            $item->save();

            Log::info('ResolvePublerPostIds: gelukt', [
                'content_item_id' => $item->id,
                'attempt'         => $this->attempt,
                'count'           => count($ids),
            ]);
            return;
        }

        // Niet (volledig) gevonden — opnieuw proberen met een grotere delay.
        if ($this->attempt < 6) {
            $delay = 15 * $this->attempt; // 15, 30, 45, 60, 75 seconden
            self::dispatch(
                $this->contentItemId,
                $this->publerAccountIds,
                $this->scheduledForIso,
                $this->attempt + 1
            )->delay(now()->addSeconds($delay));

            Log::info('ResolvePublerPostIds: nog niet compleet, retry gepland', [
                'content_item_id' => $item->id,
                'attempt'         => $this->attempt,
                'found'           => count($ids),
                'next_delay_sec'  => $delay,
            ]);
            return;
        }

        Log::warning('ResolvePublerPostIds: opgegeven na 6 pogingen', [
            'content_item_id' => $item->id,
            'found'           => count($ids),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ResolvePublerPostIdsJob mislukt', [
            'content_item_id' => $this->contentItemId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
