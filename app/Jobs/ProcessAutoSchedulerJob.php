<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\PublishSlot;
use Carbon\Carbon;
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
        $slots = PublishSlot::where('active', true)->with('client')->get();

        foreach ($slots as $slot) {
            $this->processSlot($slot);
        }
    }

    private function processSlot(PublishSlot $slot): void
    {
        $nextOccurrence = $slot->nextOccurrence();

        if (! $nextOccurrence) {
            return;
        }

        // Alleen inplannen als het slot binnen de komende 25 uur valt
        if ($nextOccurrence->gt(Carbon::now()->addHours(25))) {
            return;
        }

        // Selecteer eerstvolgende goedgekeurde item van de klant (FIFO)
        $item = ContentItem::where('client_id', $slot->client_id)
            ->where('status', ContentStatus::Goedgekeurd->value)
            ->whereNull('publer_post_id')
            ->orderBy('created_at')
            ->first();

        if (! $item) {
            Log::info("ProcessAutoScheduler: geen goedgekeurd item voor client {$slot->client_id} (slot {$slot->id})");
            return;
        }

        // Stel scheduled_for in zodat Telegram-reminder de juiste tijd kent
        $item->scheduled_for = $nextOccurrence;
        $item->save();

        $publerAccountIds = $slot->client->channels()
            ->where('active', true)
            ->whereNotNull('publer_account_id')
            ->pluck('publer_account_id')
            ->toArray();

        if (empty($publerAccountIds)) {
            Log::warning("Geen Publer account IDs voor client {$slot->client_id}");
            return;
        }

        Log::info("ProcessAutoScheduler: inplannen item #{$item->id} voor {$nextOccurrence} (client {$slot->client_id})");

        SchedulePostToPublerJob::dispatch(
            $item,
            $publerAccountIds,
            $nextOccurrence->toIso8601String()
        );
    }
}
