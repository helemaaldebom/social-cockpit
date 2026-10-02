<?php

namespace App\Jobs;

use App\Contracts\PublisherInterface;
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

class SchedulePostToPublerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;

    /** Max. aantal keer dat we doorschuiven naar een volgend slot bij conflicten. */
    private const MAX_CONFLICT_HOPS = 8;

    public function __construct(
        public readonly ContentItem $contentItem,
        public readonly array $publerAccountIds,
        public readonly string $scheduledFor,
        public readonly int $conflictHops = 0
    ) {}

    public function handle(PublisherInterface $publisher, TelegramService $telegram): void
    {
        $item = $this->contentItem->fresh();

        // Idempotentie: nooit opnieuw aanleveren als al ingepland
        if ($item->publer_post_id) {
            return;
        }

        if ($item->status !== ContentStatus::Goedgekeurd) {
            return;
        }

        $scheduledAt = Carbon::parse($this->scheduledFor, 'Europe/Amsterdam');

        // BESCHERMING BESTAANDE POSTS: staat er in Publer al iets op dit
        // tijdstip voor deze accounts (bv. handmatig ingepland, buiten de
        // Cockpit om)? Dan schuiven we DIT item door naar het volgende vrije
        // slot — we vervangen of verwijderen nooit iets dat er al staat.
        // Vergelijk binnen ±30 min i.p.v. exacte timestamp: handmatige Publer-
        // posts staan vaak op 07:30:18 i.p.v. precies 07:30:00.
        $candidateTs = $scheduledAt->copy()->utc()->getTimestamp();
        $occupied = false;
        foreach (app(PublerPublisher::class)->scheduledTimestampsForAccounts($this->publerAccountIds) as $ts) {
            if (abs($ts - $candidateTs) <= 1800) { $occupied = true; break; }
        }

        if ($occupied) {
            if ($this->conflictHops >= self::MAX_CONFLICT_HOPS) {
                $item->changeStatus(ContentStatus::Mislukt, 'Geen vrij slot gevonden (alle kandidaten bezet in Publer).');
                $telegram->notify("⚠️ Content item #{$item->id} kon niet ingepland worden: alle kandidaat-slots zijn al bezet in Publer.");
                return;
            }

            $next = $item->client->nextFreeSlot($scheduledAt->copy()->addMinute());

            if (! $next) {
                $item->changeStatus(ContentStatus::Mislukt, 'Geen vrij slot gevonden na conflict in Publer.');
                $telegram->notify("⚠️ Content item #{$item->id} kon niet ingepland worden: geen vrij slot gevonden.");
                return;
            }

            Log::info('SchedulePostToPubler: slot bezet in Publer, doorgeschoven', [
                'content_item_id' => $item->id,
                'bezet_slot'      => $scheduledAt->toIso8601String(),
                'nieuw_slot'      => $next->toIso8601String(),
            ]);

            self::dispatch($item, $this->publerAccountIds, $next->toIso8601String(), $this->conflictHops + 1);
            return;
        }

        // schedulePost() returnt direct met job_id. Polling voor de echte
        // per-netwerk post_ids gebeurt in een aparte ResolvePublerPostIdsJob,
        // zodat deze worker-job snel klaar is en de queue niet blokkeert.
        $jobId = $publisher->schedulePost($item, $this->publerAccountIds, $scheduledAt);

        $item->publer_post_id = $jobId; // tijdelijke waarde; wordt overschreven door ResolveJob met eerste echte post_id
        $item->scheduled_for  = $scheduledAt;
        $item->save();

        $item->changeStatus(ContentStatus::Ingepland, "Ingepland via Publer (job: {$jobId}).");

        // Achtergrond: haal de echte per-netwerk post_ids op (begint na 10s).
        ResolvePublerPostIdsJob::dispatch(
            $item->id,
            $this->publerAccountIds,
            $scheduledAt->toIso8601String()
        )->delay(now()->addSeconds(10));

        // Preview komt 22 uur voor publicatie binnen. Bij een slot van 07:30 NL
        // betekent dat een Telegram-bericht om 09:30 NL de dag ervoor — een
        // praktischer reviewmoment dan 07:30 (slaaptijd).
        SendTelegramPreviewJob::dispatch($item)->delay(
            $scheduledAt->copy()->subHours(22)
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SchedulePostToPublerJob mislukt', [
            'content_item_id' => $this->contentItem->id,
            'error' => $exception->getMessage(),
        ]);

        try {
            $item = $this->contentItem->fresh();
            if ($item) {
                $item->changeStatus(ContentStatus::Mislukt, 'Publer-scheduling mislukt: ' . $exception->getMessage());
            }
        } catch (\Throwable) {}

        app(TelegramService::class)->notify(
            "❌ Publer-scheduling mislukt voor content item #{$this->contentItem->id}.\n{$exception->getMessage()}"
        );
    }
}
