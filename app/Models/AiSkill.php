<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSkill extends Model
{
    protected $table = 'ai_skills';

    protected $fillable = [
        'name', 'slug', 'description', 'type', 'config', 'enabled',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
    ];
}
