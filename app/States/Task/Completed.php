<?php

namespace App\States\Task;

class Completed extends TaskState
{
    public function label(): string
    {
        return '已完成';
    }

    public function color(): string
    {
        return '#28a745';
    }

    // 状态转换前钩子 - 在任务完成时自动更新项目进度和状态
    public function beforeTransition(): void
    {
        $task = $this->getModel();
        $project = $task->project;
        
        if ($project) {
            $project->calculateProgress();

            // 检查是否所有任务都已完成，如果是则立即将项目状态转为已完成
            $totalTasks = $project->tasks()->count();
            $completedTasks = $project->tasks()->where('status', \App\States\Task\Completed::class)->count();

            if ($totalTasks > 0 && $completedTasks == $totalTasks) {
                $completedState = \App\States\Project\Completed::class;
                if (!$project->status->equals($completedState)) {
                    $project->status->transitionTo($completedState);
                }
            }
        }
    }

    // 状态转换后钩子 - 记录完成日志
    public function afterTransition(): void
    {
        $task = $this->getModel();
        
        // 记录项目日志
        \App\Models\ProjectLog::create([
            'project_id' => $task->project_id,
            'user_id' => auth()->id(),
            'action' => 'task_completed',
            'content' => "任务「{$task->name}」已完成",
        ]);
    }

    // 状态特定方法 - 检查任务是否可以重新打开
    public function canReopen(): bool
    {
        return false; // 已完成的任务不能重新打开
    }
}