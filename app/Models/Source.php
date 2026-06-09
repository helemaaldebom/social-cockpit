<?php

namespace App\Models;

use App\Enums\SourceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = ['client_id', 'url', 'category', 'active', 'last_fetched_at'];

    protected $casts = [
        'category' => SourceCategory::class,
        'active' => 'boolean',
        'last_fetched_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(SourceArticle::class);
    }
}
