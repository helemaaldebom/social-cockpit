<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceArticle extends Model
{
    protected $fillable = ['source_id', 'external_url', 'title', 'content_item_id', 'fetched_at'];

    protected $casts = ['fetched_at' => 'datetime'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
