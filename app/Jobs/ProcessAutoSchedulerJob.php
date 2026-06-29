<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutoSchedulerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Veiligheidsnet voor items die ondanks GenerateContentTextJob's
        // auto-schedule alsnog op Goedgekeurd staan (bv. handmatig goedgekeurd
        // via Filament, of auto-schedule mislukte omdat er geen vrij slot was).
        $items = ContentItem::with('client')
            ->where('status', ContentStatus::Goedgekeurd->value)
            ->whereNull('publer_post_id')
            ->orderBy('created_at')
            ->get();

        foreach ($items as $item) {
            $this->processItem($item);
        }
    }

    private function processItem(ContentItem $item): void
    {
        $client = $item->client;
        if (! $client) {
            return;
        }

        $publerAccountIds = $client->channels()
            ->where('active', true)
            ->whereNotNull('publer_account_id')
            ->pluck('publer_account_id')
            ->toArray();

        if (empty($publerAccountIds)) {
            Log::warning("ProcessAutoScheduler: geen Publer-accounts voor client {$client->id}");
            return;
        }

        $slot = $client->nextFreeSlot();
        if (! $slot) {
            Log::info("ProcessAutoScheduler: geen vrij slot voor item #{$item->id} (client {$client->id})");
            return;
        }

        Log::info("ProcessAutoScheduler: inplannen item #{$item->id} voor {$slot} (client {$client->id})");

        SchedulePostToPublerJob::dispatch(
            $item,
            $publerAccountIds,
            $slot->toIso8601String()
        );
    }
}
