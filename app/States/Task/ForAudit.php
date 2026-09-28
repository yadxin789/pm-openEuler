<?php

namespace App\States\Task;

use App\Notifications\AuditRequestNotification;

class ForAudit extends TaskState
{
    public function label(): string
    {
        return '待审核';
    }

    public function color(): string
    {
        return '#17a2b8';
    }

    // 状态特定方法 - 获取审核人
    public function getAuditor(): ?\App\Models\User
    {
        $task = $this->getModel();
        return $task->auditor;
    }

    // 状态特定方法 - 检查是否超时
    public function isOverdue(): bool
    {
        $task = $this->getModel();
        $deadline = $task->updated_at->addDays(3);
        return now()->gt($deadline);
    }

    // 状态转换后钩子 - 通知审核人
    public function afterTransition(): void
    {
        $task = $this->getModel();

        // 清除执行人的待办事项
        \App\Models\Todo::where('related_type', 'task')
            ->where('related_id', $task->id)
            ->where('user_id', $task->executor_id)
            ->where('type', 'task')
            ->delete();

        // 为审核人创建待办事项
        \App\Models\Todo::create([
            'user_id' => $task->auditor_id,
            'type' => 'audit',
            'title' => "审核：【{$task->project->name}】{$task->name}",
            'related_type' => 'task',
            'related_id' => $task->id,
        ]);

        // 使用 Laravel Notification 系统发送通知
        $auditor = $task->auditor;
        if ($auditor) {
            $auditor->notify(new AuditRequestNotification(
                taskName: $task->name,
                projectName: $task->project->name ?? '',
                taskId: $task->id,
                senderId: auth()->id(),
            ));
        }
    }
}
