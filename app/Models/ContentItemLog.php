<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItemLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_item_id', 'from_status', 'to_status', 'note', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
