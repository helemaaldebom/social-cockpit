<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'tone_of_voice', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function publishSlots(): HasMany
    {
        return $this->hasMany(PublishSlot::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function examples(): HasMany
    {
        return $this->hasMany(ClientExample::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Vind het eerstvolgende vrije publish-slot voor deze klant.
     *
     * "Vrij" = er staat nog geen ContentItem (status ingepland of geplaatst)
     * binnen ±30 minuten van dat tijdstip. Loopt door alle actieve slots heen,
     * kiest de chronologisch eerst-mogelijke, en springt naar het volgende
     * moment als er al iets staat. Max 30 iteraties als veiligheidsklem.
     *
     * Resultaat is een Carbon in de slot-tijdzone (Europe/Amsterdam).
     */
    public function nextFreeSlot(?Carbon $after = null): ?Carbon
    {
        $slots = $this->publishSlots()->where('active', true)->get();

        if ($slots->isEmpty()) {
            return null;
        }

        $cursor = $after ?? Carbon::now();

        for ($i = 0; $i < 30; $i++) {
            $candidate = $slots
                ->map(fn (PublishSlot $slot) => $slot->nextOccurrence($cursor))
                ->filter()
                ->sort()
                ->first();

            if (! $candidate) {
                return null;
            }

            $utc = $candidate->copy()->utc();

            $taken = ContentItem::where('client_id', $this->id)
                ->whereIn('status', ['ingepland', 'geplaatst'])
                ->whereBetween('scheduled_for', [
                    $utc->copy()->subMinutes(30),
                    $utc->copy()->addMinutes(30),
                ])
                ->exists();

            if (! $taken) {
                return $candidate;
            }

            // Datum is bezet — probeer een moment later opnieuw te zoeken.
            $cursor = $candidate->copy()->addMinutes(1);
        }

        return null;
    }
}
