<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Jobs\ProcessAutoSchedulerJob;
use App\Models\ContentItem;
use Carbon\Carbon;

/**
 * Het ENIGE pad naar publicatie: een mens keurt goed (Telegram-knop of
 * Filament), daarna wordt ingepland. Niets anders mag een item op
 * "goedgekeurd" zetten en naar Publer sturen.
 */
class ApprovalService
{
    /**
     * @return Carbon|null gekozen publicatiemoment, of null als er (nog) geen slot is
     */
    public function approve(ContentItem $item, string $via): ?Carbon
    {
        $item->changeStatus(ContentStatus::Goedgekeurd, "Goedgekeurd via {$via}.");

        return ProcessAutoSchedulerJob::scheduleApproved($item->fresh(['client', 'channels']));
    }

    public function reject(ContentItem $item, string $via): void
    {
        $item->changeStatus(ContentStatus::Mislukt, "Afgewezen via {$via} — wordt niet geplaatst.");
    }
}
