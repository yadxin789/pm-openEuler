<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileVersion extends Model
{
    protected $fillable = [
        'file_id', 'task_id', 'version', 'path', 'uploader_id', 'auditor_id', 'status'
    ];

    /**
     * 所属任务（历史版本快照归任务所有，任务删除时随之清理）。
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function file()
    {
        return $this->belongsTo(File::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}