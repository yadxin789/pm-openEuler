<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiConversation extends Model
{
    use SoftDeletes;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id', 'title', 'skill_slug', 'message_count', 'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
    ];

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(AiAttachment::class, 'conversation_id')->orderBy('id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 会话摘要（用于侧栏列表展示）
     */
    public function excerpt(int $limit = 40): string
    {
        $first = $this->messages()->where('role', 'user')->first();
        if (!$first || blank($first->content)) {
            return '（无内容）';
        }
        return mb_strimwidth(str_replace(["\r", "\n"], ' ', $first->content), 0, $limit, '…');
    }
}
