<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Jobs\DeletePublerPostsJob;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'title', 'brief', 'original_text', 'generated_text',
        'media_path', 'media_paths',
        'status', 'scheduled_for', 'publer_post_id', 'publer_post_ids',
        'telegram_message_id', 'source_article_id',
    ];

    protected $attributes = [
        'status' => 'concept',
    ];

    protected $casts = [
        'status' => ContentStatus::class,
        'scheduled_for' => 'datetime',
        'telegram_message_id' => 'integer',
        'media_paths' => 'array',
        'publer_post_ids' => 'array',
    ];

    /**
     * Bij (soft-)delete via Filament/Eloquent verwijderen we ook de bijbehorende
     * posts in Publer. We dispatchen een job met de IDs los van het model, zodat
     * deze ook werkt nadat het ContentItem is verdwenen / gesoftdeleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (ContentItem $item) {
            $ids = $item->publer_post_ids ?: ($item->publer_post_id ? [$item->publer_post_id] : []);

            if (! empty($ids)) {
                DeletePublerPostsJob::dispatch(array_values($ids), (int) $item->id);
            }
        });
    }

    /**
     * Bewaar scheduled_for altijd in UTC.
     *
     * Eloquent slaat een Carbon-instance op met format('Y-m-d H:i:s') in de
     * TIJDZONE van de Carbon zelf — er gebeurt geen automatische UTC-conversie
     * bij save. Bij read castet Laravel de DB-string naar app.timezone (UTC).
     * Als we hier niet expliciet naar UTC zetten, krijg je een mismatch: een
     * Carbon van 07:30 Europe/Amsterdam wordt 07:30 in de DB opgeslagen en
     * vervolgens als 07:30 UTC (= 09:30 NL) teruggelezen.
     */
    public function setScheduledForAttribute($value): void
    {
        $this->attributes['scheduled_for'] = $value
            ? Carbon::parse($value)->setTimezone('UTC')->format('Y-m-d H:i:s')
            : null;
    }

    /**
     * Gecombineerde lijst van media-paths (zowel media_path als media_paths).
     * Eerst is doorgaans de "hoofdmedia". Lege strings/nulls worden gefilterd.
     */
    public function allMediaPaths(): array
    {
        $paths = $this->media_paths ?? [];
        if ($this->media_path && ! in_array($this->media_path, $paths, true)) {
            array_unshift($paths, $this->media_path);
        }
        return array_values(array_filter($paths, fn ($p) => filled($p)));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'content_item_channel');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ContentItemLog::class);
    }

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(SourceArticle::class);
    }

    /**
     * Transition to a new status, validating the transition and writing an audit log.
     *
     * @throws InvalidStatusTransitionException
     */
    public function changeStatus(ContentStatus $newStatus, ?string $note = null): void
    {
        $currentStatus = $this->status;

        if (! $currentStatus->canTransitionTo($newStatus)) {
            throw new InvalidStatusTransitionException($currentStatus, $newStatus);
        }

        $this->logs()->create([
            'from_status' => $currentStatus->value,
            'to_status' => $newStatus->value,
            'note' => $note,
        ]);

        $this->status = $newStatus;
        $this->save();
    }
}
