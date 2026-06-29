<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Exceptions\InvalidStatusTransitionException;
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
        'status', 'scheduled_for', 'publer_post_id', 'telegram_message_id',
        'source_article_id',
    ];

    protected $attributes = [
        'status' => 'concept',
    ];

    protected $casts = [
        'status' => ContentStatus::class,
        'scheduled_for' => 'datetime',
        'telegram_message_id' => 'integer',
        'media_paths' => 'array',
    ];

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
