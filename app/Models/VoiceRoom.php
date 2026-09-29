<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceRoom extends Model
{
    protected $fillable = [
        'project_id',
        'host_id',
        'current_presenter_id',
        'janus_room_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function presenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_presenter_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }

    /**
     * 惰性获取（或创建）某项目对应的会议房。
     * host_id 固定取 project.creator_id。
     */
    public static function forProject(Project $project): self
    {
        return static::firstOrCreate(
            ['project_id' => $project->id],
            ['host_id' => $project->creator_id],
        );
    }
}
