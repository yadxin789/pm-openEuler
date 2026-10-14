<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'sender_id', 'type', 'title', 'content',
        'related_type', 'related_id', 'is_read', 'read_at'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function markAsRead()
    {
        $this->is_read = true;
        $this->read_at = now();
        $this->save();
    }
}