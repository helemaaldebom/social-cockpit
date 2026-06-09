<?php

namespace App\Models;

use App\Enums\SocialNetwork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientExample extends Model
{
    protected $fillable = ['client_id', 'network', 'content', 'label', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
