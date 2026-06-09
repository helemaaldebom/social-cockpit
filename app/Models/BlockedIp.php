<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    public $timestamps = false;

    protected $fillable = ['ip_address', 'blocked_until', 'reason', 'attempts', 'created_at'];

    protected $casts = [
        'blocked_until' => 'datetime',
        'created_at' => 'datetime',
    ];

    public static function isBlocked(string $ip): bool
    {
        return static::where('ip_address', $ip)
            ->where('blocked_until', '>', now())
            ->exists();
    }
}
