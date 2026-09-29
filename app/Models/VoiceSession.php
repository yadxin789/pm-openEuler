<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceSession extends Model
{
    protected $fillable = [
        'voice_room_id',
        'started_by',
        'collab_document_id',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(VoiceRoom::class, 'voice_room_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CollabDocument::class, 'collab_document_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(VoiceParticipant::class);
    }
}
