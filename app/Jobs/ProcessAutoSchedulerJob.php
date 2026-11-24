<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
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
        // Plant ALLEEN items in die een mens expliciet heeft goedgekeurd
        // (status Goedgekeurd) en die nog niet bij Publer staan. Er bestaat
        // geen pad meer dat zonder goedkeuring naar Publer leidt.
        $items = ContentItem::with('client', 'channels')
            ->where('status', ContentStatus::Goedgekeurd->value)
            ->whereNull('publer_post_id')
            ->orderBy('created_at')
            ->get();

        foreach ($items as $item) {
            self::scheduleApproved($item);
        }
    }

    /**
     * Plant één goedgekeurd item in bij Publer. Gebruikt de kanalen van het
     * item zelf (niet alle kanalen van de klant) en respecteert een expliciet
     * gekozen toekomstige publicatietijd; anders het eerstvolgende vrije slot.
     *
     * @return Carbon|null het gekozen tijdstip, of null als inplannen (nog) niet kan
     */
    public static function scheduleApproved(ContentItem $item): ?Carbon
    {
        if ($item->status !== ContentStatus::Goedgekeurd || $item->publer_post_id) {
            return null;
        }

        $client = $item->client;
        if (! $client) {
            return null;
        }

        $publerAccountIds = $item->channels
            ->where('active', true)
            ->pluck('publer_account_id')
            ->filter()
            ->values()
            ->all();

        if (empty($publerAccountIds)) {
            Log::warning("Inplannen overgeslagen: item #{$item->id} heeft geen kanalen met Publer-account.");
            return null;
        }

        $slot = ($item->scheduled_for && $item->scheduled_for->isFuture())
            ? $item->scheduled_for->copy()->setTimezone('Europe/Amsterdam')
            : $client->nextFreeSlot();

        if (! $slot) {
            Log::info("Inplannen overgeslagen: geen vrij slot voor item #{$item->id} (client {$client->id})");
            return null;
        }

        Log::info("Goedgekeurd item #{$item->id} wordt ingepland voor {$slot} (client {$client->id})");

        SchedulePostToPublerJob::dispatch($item, $publerAccountIds, $slot->toIso8601String());

        return $slot;
    }
}
