<?php

namespace App\Notifications;

class TaskAssignedNotification extends BaseNotification
{
    public function __construct(
        public string $taskName,
        public string $projectName,
        public int $taskId,
        public ?int $senderId = null,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        return $this->buildData(
            type: 'task',
            title: '您有新的任务',
            content: "【{$this->projectName}】{$this->taskName}",
            relatedType: 'task',
            relatedId: $this->taskId,
            senderId: $this->senderId,
        );
    }
}
