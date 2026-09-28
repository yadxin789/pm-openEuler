<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'project_id', 'user_id', 'receiver_id', 'is_private',
        'content', 'file_path', 'file_name',
    ];

    protected $casts = [
        'is_private' => 'boolean',
    ];

    /**
     * 只查询群聊消息
     */
    public function scopeGroup($query)
    {
        return $query->where('is_private', false);
    }

    /**
     * 只查询私聊消息
     */
    public function scopePrivate($query)
    {
        return $query->where('is_private', true);
    }

    /**
     * 查询两个用户之间的私聊消息
     */
    public function scopeBetween($query, $userId1, $userId2)
    {
        return $query->private()->where(function ($q) use ($userId1, $userId2) {
            $q->where(function ($sub) use ($userId1, $userId2) {
                $sub->where('user_id', $userId1)->where('receiver_id', $userId2);
            })->orWhere(function ($sub) use ($userId1, $userId2) {
                $sub->where('user_id', $userId2)->where('receiver_id', $userId1);
            });
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
