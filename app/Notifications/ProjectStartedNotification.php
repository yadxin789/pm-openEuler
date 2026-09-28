<?php

namespace App\Notifications;

class ProjectStartedNotification extends BaseNotification
{
    public function __construct(
        public string $projectName,
        public int $projectId,
        public ?int $senderId = null,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        return $this->buildData(
            type: 'project_started',
            title: '项目已启动',
            content: "项目「{$this->projectName}」已启动，请开始执行任务",
            relatedType: 'project',
            relatedId: $this->projectId,
            senderId: $this->senderId,
        );
    }
}
