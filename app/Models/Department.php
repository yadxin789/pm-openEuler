<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'type', 'sort_order'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function departmentFiles()
    {
        return $this->hasMany(DepartmentFile::class);
    }

    public function executors()
    {
        return $this->users()->whereHas('role', function($q) {
            $q->where('code', 'task_executor');
        });
    }

    public function auditors()
    {
        return $this->users()->whereHas('role', function($q) {
            $q->where('code', 'task_auditor');
        });
    }

    public function designers()
    {
        return $this->executors();
    }

    public function managers()
    {
        return $this->auditors();
    }

    public function scopeProjectDepartments($query)
    {
        return $query->where('type', 'project')->orderBy('sort_order');
    }

    public function scopeNonProjectDepartments($query)
    {
        return $query->where('type', 'non-project');
    }
}