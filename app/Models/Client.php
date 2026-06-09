<?php

namespace App\Models;

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
}
