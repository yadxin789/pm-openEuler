<?php

namespace App\States\Project;

use App\Notifications\ProjectStartedNotification;

class InProgress extends ProjectState
{
    public function label(): string
    {
        return '进行中';
    }

    public function color(): string
    {
        return '#ffc107';
    }

    // 状态特定方法 - 检查是否应该标记为逾期
    public function shouldMarkAsOverdue(): bool
    {
        $project = $this->getModel();
        return $project->end_date->isPast() && $project->progress < 100;
    }

    // 状态特定方法 - 获取剩余天数
    public function getRemainingDays(): int
    {
        $project = $this->getModel();
        return max(0, now()->diffInDays($project->end_date, false));
    }

    // 状态转换后钩子 - 通知项目参与者
    public function afterTransition(): void
    {
        $project = $this->getModel();

        // 使用 Laravel Notification 系统通知所有参与者
        $participants = $project->participants();
        foreach ($participants as $user) {
            $user->notify(new ProjectStartedNotification(
                projectName: $project->name,
                projectId: $project->id,
                senderId: auth()->id(),
            ));
        }
    }
}
