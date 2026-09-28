<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectLog extends Model
{
    protected $fillable = ['project_id', 'user_id', 'action', 'content'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}