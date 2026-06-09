<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'day_of_week', 'time', 'timezone',
        'interval_weeks', 'reference_date', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'reference_date' => 'date',
        'interval_weeks' => 'integer',
        'day_of_week' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Determine if this slot is active for a given date (handles bi-weekly logic).
     */
    public function isActiveOnDate(Carbon $date): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->interval_weeks <= 1) {
            return true;
        }

        if (! $this->reference_date) {
            return false;
        }

        $ref = $this->reference_date->copy()->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $target = $date->copy()->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $weekDiff = (int) round($ref->diffInDays($target) / 7);

        return ($weekDiff % $this->interval_weeks) === 0;
    }

    /**
     * Get the next upcoming datetime for this slot in the slot's timezone.
     */
    public function nextOccurrence(?Carbon $after = null): ?Carbon
    {
        if (! $this->active) {
            return null;
        }

        $now = $after ?? Carbon::now($this->timezone);
        $candidate = $now->copy()->setTimezone($this->timezone);

        for ($i = 0; $i < 30; $i++) {
            $isoDow = $candidate->isoWeekday(); // 1=Mon, 7=Sun
            if ($isoDow === $this->day_of_week) {
                [$h, $m, $s] = explode(':', $this->time);
                $slotTime = $candidate->copy()->setTime((int)$h, (int)$m, (int)$s);

                if ($slotTime->gt($now) && $this->isActiveOnDate($slotTime)) {
                    return $slotTime;
                }
            }
            $candidate->addDay();
        }

        return null;
    }
}
