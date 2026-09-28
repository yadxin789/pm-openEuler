<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 项目模板模型
 * 用于存储项目模板基本信息
 */
class ProjectTemplate extends Model
{
    // 可填充字段
    protected $fillable = ['name', 'description', 'user_id'];

    /**
     * 获取模板创建人
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 获取模板中的任务配置
     */
    public function templateTasks()
    {
        return $this->hasMany(TemplateTask::class)->orderBy('sort_order');
    }
}