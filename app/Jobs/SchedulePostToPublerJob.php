<?php

namespace App\Jobs;

use App\Contracts\PublisherInterface;
use App\Enums\ContentStatus;
use App\Models\ContentItem;
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

    public function __construct(
        public readonly ContentItem $contentItem,
        public readonly array $publerAccountIds,
        public readonly string $scheduledFor
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
