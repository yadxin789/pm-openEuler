<?php

namespace App\Notifications;

class ExtensionResultNotification extends BaseNotification
{
    public function __construct(
        public string $taskName,
        public string $projectName,
        public int $taskId,
        public bool $approved,
        public int $days,
        public ?string $reason = null,
        public ?int $senderId = null,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        if ($this->approved) {
            return $this->buildData(
                type: 'extension_result',
                title: '您的延期申请已通过',
                content: "任务「{$this->taskName}」延期 {$this->days} 天的申请已通过",
                relatedType: 'task',
                relatedId: $this->taskId,
                senderId: $this->senderId,
            );
        }

        return $this->buildData(
            type: 'extension_result',
            title: '您的延期申请已驳回',
            content: "任务「{$this->taskName}」的延期申请已驳回" . ($this->reason ? "：{$this->reason}" : ''),
            relatedType: 'task',
            relatedId: $this->taskId,
            senderId: $this->senderId,
        );
    }
}
