<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WecomMessageLog extends Model
{
    protected $fillable = [
        'user_id', 'msg_type', 'title', 'content', 'response_code', 'response_body',
    ];

    protected $casts = [
        'response_body' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isSuccess(): bool
    {
        return $this->response_code == '0';
    }
}
