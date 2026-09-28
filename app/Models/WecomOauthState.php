<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WecomOauthState extends Model
{
    public $timestamps = false;

    protected $fillable = ['state', 'redirect_url', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public static function valid(string $state): ?self
    {
        return static::where('state', $state)
            ->where('expires_at', '>', now())
            ->first();
    }
}
