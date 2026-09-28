<?php

namespace App\Notifications;

class AuditRequestNotification extends BaseNotification
{
    public function __construct(
        public string $taskName,
        public string $projectName,
        public int $taskId,
        public ?int $senderId = null,
        public bool $isUpdate = false,
        public ?int $version = null,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        if ($this->isUpdate) {
            $title = '您有文件更新需要审核';
            $content = "任务「{$this->taskName}」已更新文件（v{$this->version}），请审核";
        } else {
            $title = '您有新文件需要审核';
            $content = "任务「{$this->taskName}」已提交文件，请审核";
        }

        return $this->buildData(
            type: 'audit',
            title: $title,
            content: $content,
            relatedType: 'task',
            relatedId: $this->taskId,
            senderId: $this->senderId,
        );
    }
}
