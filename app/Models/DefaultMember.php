<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DefaultMember extends Model
{
    protected $fillable = ['department_id', 'executor_id', 'auditor_id'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}