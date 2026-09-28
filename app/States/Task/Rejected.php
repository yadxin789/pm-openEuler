<?php

namespace App\States\Task;

use App\Notifications\AuditResultNotification;

class Rejected extends TaskState
{
    public function label(): string
    {
        return '已驳回';
    }

    public function color(): string
    {
        return '#dc3545';
    }

    // 状态特定方法 - 获取驳回原因
    public function getRejectionReason(): ?string
    {
        $task = $this->getModel();
        $file = $task->files()->where('status', 'rejected')->latest()->first();
        return $file ? $file->audit_reason : null;
    }

    // 状态转换后钩子 - 通知执行人
    public function afterTransition(): void
    {
        $task = $this->getModel();

        \Log::info('Rejected状态钩子执行', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'executor_id' => $task->executor_id,
            'auditor_id' => $task->auditor_id,
        ]);

        try {
            // 为执行人创建待办事项
            \App\Models\Todo::create([
                'user_id' => $task->executor_id,
                'type' => 'task',
                'title' => "【{$task->project->name}】{$task->name}（已驳回）",
                'related_type' => 'task',
                'related_id' => $task->id,
            ]);

            \Log::info('待办事项已创建', [
                'user_id' => $task->executor_id,
                'task_id' => $task->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('创建待办事项失败', [
                'error' => $e->getMessage(),
                'task_id' => $task->id,
            ]);
        }

        try {
            // 使用 Laravel Notification 系统发送通知
            $executor = $task->executor;
            if ($executor) {
                $executor->notify(new AuditResultNotification(
                    taskName: $task->name,
                    projectName: $task->project->name ?? '',
                    taskId: $task->id,
                    approved: false,
                    reason: $this->getRejectionReason(),
                    senderId: auth()->id() ?? $task->auditor_id,
                ));
            }

            \Log::info('通知已发送', [
                'user_id' => $task->executor_id,
                'task_id' => $task->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('发送通知失败', [
                'error' => $e->getMessage(),
                'task_id' => $task->id,
            ]);
        }
    }
}
