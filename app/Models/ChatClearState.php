<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatClearState extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'chat_type',
        'other_user_id',
        'conversation_key',
        'cleared_at',
    ];

    protected $casts = [
        'cleared_at' => 'datetime',
    ];
}
