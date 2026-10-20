<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = [
        'conversation_id', 'role', 'content', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function attachments()
    {
        return $this->hasMany(AiAttachment::class, 'message_id')->orderBy('id');
    }
}
