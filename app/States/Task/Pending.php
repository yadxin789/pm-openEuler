<?php

namespace App\States\Task;

class Pending extends TaskState
{
    public function label(): string
    {
        return '等待中';
    }

    public function color(): string
    {
        return '#6c757d';
    }

    // 状态特定方法 - 检查是否可以开始工作
    public function canStart(): bool
    {
        $task = $this->getModel();
        return $task->start_date->lte(now());
    }

    // 状态特定方法 - 获取状态描述
    public function description(): string
    {
        $task = $this->getModel();
        
        if (!$this->canStart()) {
            return "任务将在 {$task->start_date->format('Y-m-d')} 开始";
        }
        
        return "任务已就绪，可以开始执行";
    }

    // 状态转换后钩子 - 创建待办事项
    public function afterTransition(): void
    {
        $task = $this->getModel();
        
        // 为执行人创建待办事项
        \App\Models\Todo::create([
            'user_id' => $task->executor_id,
            'type' => 'task',
            'title' => "【{$task->project->name}】{$task->name}",
            'related_type' => 'task',
            'related_id' => $task->id,
        ]);
    }
}