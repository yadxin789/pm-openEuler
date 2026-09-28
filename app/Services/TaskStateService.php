<?php

namespace App\Services;

use App\Models\Task;

class TaskStateService
{
    /**
     * 获取任务状态详情
     * 展示如何使用状态特定方法
     */
    public function getTaskStateDetails(Task $task): array
    {
        $status = $task->status;
        
        return [
            'label' => $status->label(),
            'color' => $status->color(),
            'description' => method_exists($status, 'description') ? $status->description() : null,
            'can_start' => method_exists($status, 'canStart') ? $status->canStart() : null,
            'can_reopen' => method_exists($status, 'canReopen') ? $status->canReopen() : null,
            'is_overdue' => method_exists($status, 'isOverdue') ? $status->isOverdue() : null,
        ];
    }

    /**
     * 智能状态转换
     * 根据当前状态和条件决定是否可以转换
     */
    public function smartTransition(Task $task, string $targetState): bool
    {
        $currentState = $task->status;
        
        // 检查是否允许转换
        if (!$currentState->canTransitionTo($targetState)) {
            return false;
        }

        // 特定状态的业务逻辑检查
        if ($currentState instanceof \App\States\Task\Pending) {
            // 等待中的任务只有在开始日期到达后才能开始
            if ($targetState === \App\States\Task\InProgress::class && !$currentState->canStart()) {
                return false;
            }
        }

        // 执行转换
        $task->status->transitionTo($targetState);
        return true;
    }

    /**
     * 批量状态检查
     * 展示如何使用状态检查功能
     */
    public function batchCheckTasks(array $taskIds): array
    {
        $tasks = Task::whereIn('id', $taskIds)->get();
        $results = [];

        foreach ($tasks as $task) {
            $status = $task->status;
            
            $results[$task->id] = [
                'task_name' => $task->name,
                'status' => $status->label(),
                'needs_attention' => false,
                'reason' => null,
            ];

            // 根据状态类型进行不同的检查
            if ($status instanceof \App\States\Task\ForAudit) {
                if ($status->isOverdue()) {
                    $results[$task->id]['needs_attention'] = true;
                    $results[$task->id]['reason'] = '审核已超时';
                }
            } elseif ($status instanceof \App\States\Task\Pending) {
                if (!$status->canStart()) {
                    $results[$task->id]['needs_attention'] = true;
                    $results[$task->id]['reason'] = '任务尚未开始';
                }
            } elseif ($status instanceof \App\States\Task\Overdue) {
                $results[$task->id]['needs_attention'] = true;
                $results[$task->id]['reason'] = '任务已逾期';
            }
        }

        return $results;
    }
}