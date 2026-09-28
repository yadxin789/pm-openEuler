<?php

namespace App\Notifications;

class TaskDueNotification extends BaseNotification
{
    public function __construct(
        public string $taskName,
        public string $projectName,
        public string $endDate,
        public int $daysLeft,
        public int $taskId,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        $urgencyText = $this->daysLeft === 0 ? '今天' : "还剩 {$this->daysLeft} 天";

        return $this->buildData(
            type: 'deadline_reminder',
            title: '任务即将到期',
            content: "任务「{$this->taskName}」（项目：{$this->projectName}）{$urgencyText}到期，截止日期：{$this->endDate}",
            relatedType: 'task',
            relatedId: $this->taskId,
        );
    }
}
