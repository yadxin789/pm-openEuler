<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceParticipant extends Model
{
    protected $fillable = [
        'voice_session_id',
        'user_id',
        'joined_at',
        'left_at',
        'was_presenter',
        'leave_reason',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'was_presenter' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class, 'voice_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
