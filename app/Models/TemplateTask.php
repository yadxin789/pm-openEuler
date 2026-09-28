<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 模板任务配置模型
 * 用于存储项目模板中每个任务的配置信息
 */
class TemplateTask extends Model
{
    // 可填充字段
    protected $fillable = [
        'project_template_id',
        'task_type_id',
        'department_id',
        'executor_id',
        'auditor_id',
        'days',
        'sort_order'
    ];

    /**
     * 获取所属项目模板
     */
    public function projectTemplate()
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    /**
     * 获取任务类型
     */
    public function taskType()
    {
        return $this->belongsTo(TaskType::class);
    }

    /**
     * 获取所属部门
     */
    public function department()
    {
        return $this->belongsTo(\App\Models\Department::class);
    }

    /**
     * 获取执行人
     */
    public function executor()
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    /**
     * 获取审核人
     */
    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}