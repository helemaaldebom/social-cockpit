<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\PublerPublisher;
use App\Services\TelegramService;
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
 * Belangrijk: Publer's bulk-schedule meldt "complete" óók als er in
 * werkelijkheid 0 posts zijn aangemaakt (bv. een kapotte kanaalkoppeling).
 * Daarom verifiëren we hier dat de posts écht bestaan. Na de laatste poging:
 * - 0 gevonden  -> item op "mislukt" + Telegram-alert (niets staat live).
 * - deels gevonden -> bewaren wat er is, maar Telegram-waarschuwing dat
 *   één of meer netwerken niet zijn ingepland.
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

    public function handle(PublerPublisher $publisher, TelegramService $telegram): void
    {
        $item = ContentItem::find($this->contentItemId);
        if (! $item) {
            return;
        }

        $scheduledFor = Carbon::parse($this->scheduledForIso);
        $ids = $publisher->matchScheduledPostIds($this->publerAccountIds, $scheduledFor);

        // BESCHERMING: adopteer nooit posts die al bij een ander content item
        // horen. Alleen "onbeclaimde" posts op dit tijdstip kunnen van ons zijn.
        $ids = array_values(array_diff($ids, ContentItem::claimedPublerPostIds($this->contentItemId)));

        $expected = count($this->publerAccountIds);

        if (count($ids) >= $expected) {
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

        // Nog niet (volledig) gevonden — opnieuw proberen met grotere delay.
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

        // Laatste poging voorbij: eindoordeel vellen.
        $when = $scheduledFor->copy()->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i');

        if (count($ids) === 0) {
            // Publer heeft niets aangemaakt — het item staat NIET live.
            $item->publer_post_ids = null;
            $item->publer_post_id  = null;
            $item->save();

            $item->changeStatus(
                ContentStatus::Mislukt,
                "Publer maakte geen posts aan voor {$when} (0 van {$expected} netwerken). Mogelijk een kanaalkoppeling die niet (meer) werkt."
            );

            Log::warning('ResolvePublerPostIds: 0 posts in Publer — item op mislukt', [
                'content_item_id' => $item->id,
                'expected'        => $expected,
            ]);

            $telegram->notify(
                "❌ Content #{$item->id} \"{$item->title}\" is NIET ingepland: Publer maakte 0 van {$expected} posts aan voor {$when}.\n"
                . 'Controleer de kanaalkoppelingen van ' . ($item->client?->name ?? 'de klant') . ' in Publer.'
            );
            return;
        }

        // Deels gelukt: bewaren wat er is, maar waarschuwen over de rest.
        $item->publer_post_ids = $ids;
        $item->publer_post_id  = $ids[0];
        $item->save();

        $missing = $expected - count($ids);

        Log::warning('ResolvePublerPostIds: gedeeltelijk ingepland', [
            'content_item_id' => $item->id,
            'gevonden'        => count($ids),
            'verwacht'        => $expected,
        ]);

        $telegram->notify(
            "⚠️ Content #{$item->id} \"{$item->title}\": slechts " . count($ids) . " van {$expected} netwerken ingepland in Publer voor {$when} ({$missing} mislukt).\n"
            . 'Controleer de kanaalkoppelingen van ' . ($item->client?->name ?? 'de klant') . ' in Publer.'
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ResolvePublerPostIdsJob mislukt', [
            'content_item_id' => $this->contentItemId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
