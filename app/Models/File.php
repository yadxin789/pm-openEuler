<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use SoftDeletes;

    /**
     * 文件审核状态常量。
     * 注意：历史上 canDownload() 误用 'accepted' 作判断，导致未审核文件对全体项目成员可下载，
     * 现统一收敛到下列常量，避免再次出现字面量漂移。
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'task_id', 'uploader_id', 'auditor_id', 'name', 'original_name',
        'path', 'size', 'mime_type', 'version', 'status', 'audit_time', 'audit_reason'
    ];

    protected $casts = [
        'audit_time' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * 穿透 Task 直达 Project 的便捷关系
     */
    public function project()
    {
        return $this->hasOneThrough(Project::class, Task::class, 'id', 'id', 'task_id', 'project_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    public function versions()
    {
        return $this->hasMany(FileVersion::class)->orderBy('version', 'desc');
    }

    public function canPreview($userId)
    {
        // 上传人可以预览
        if ($this->uploader_id == $userId) {
            return true;
        }
        
        // 审核人可以预览
        if ($this->auditor_id == $userId) {
            return true;
        }
        
        // 项目创建人可以预览该项目的所有文件
        try {
            if ($this->task_id) {
                $task = $this->task ?? Task::find($this->task_id);
                if ($task && $task->project_id) {
                    $project = $task->project ?? Project::find($task->project_id);
                    if ($project && $project->creator_id == $userId) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果查询失败，返回false
            return false;
        }
        
        // 项目总经理可以预览所有文件
        try {
            $user = User::find($userId);
            if ($user && $user->isProjectManager()) {
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }
        
        return false;
    }

    public function getFormattedSizeAttribute()
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * 检查用户是否有权下载此文件
     * 下载权限比预览更严格：仅文件审核通过后才允许项目成员下载
     */
    public function canDownload($userId)
    {
        // 上传人可以下载
        if ($this->uploader_id == $userId) {
            return true;
        }

        // 项目创建人可以下载
        try {
            if ($this->task_id) {
                $task = $this->task ?? Task::find($this->task_id);
                if ($task && $task->project_id) {
                    $project = $task->project ?? Project::find($task->project_id);
                    if ($project && $project->creator_id == $userId) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        // 项目总经理可以下载所有文件
        try {
            $user = User::find($userId);
            if ($user && $user->isProjectManager()) {
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }

        // 其他项目成员：只有审核通过的文件才能下载
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        try {
            if ($this->task_id) {
                $task = $this->task ?? Task::find($this->task_id);
                if ($task && $task->project_id) {
                    $project = $task->project ?? Project::find($task->project_id);
                    if ($project) {
                        $members = $project->getAllMembersAttribute();
                        if ($members && $members->contains('id', $userId)) {
                            return true;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    public function isPreviewable()
    {
        // 所有文件都显示预览按钮，不支持的格式在预览时会弹出提示
        return true;
    }
}