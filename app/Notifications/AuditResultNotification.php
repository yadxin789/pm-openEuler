<?php

namespace App\Notifications;

class AuditResultNotification extends BaseNotification
{
    public function __construct(
        public string $taskName,
        public string $projectName,
        public int $taskId,
        public bool $approved,
        public ?string $reason = null,
        public ?int $senderId = null,
        public ?int $version = null,
        public bool $isUpdate = false,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        if ($this->approved) {
            return $this->buildData(
                type: 'audit_result',
                title: '审核通过',
                content: "任务「{$this->taskName}」审核已通过" . ($this->reason ? "：{$this->reason}" : ''),
                relatedType: 'task',
                relatedId: $this->taskId,
                senderId: $this->senderId,
            );
        }

        if ($this->isUpdate) {
            return $this->buildData(
                type: 'audit_result',
                title: '您的更新文件已驳回',
                content: "任务「{$this->taskName}」的更新文件已被驳回" . ($this->reason ? "：{$this->reason}" : ''),
                relatedType: 'task',
                relatedId: $this->taskId,
                senderId: $this->senderId,
            );
        }

        return $this->buildData(
            type: 'audit_result',
            title: '您上传的文件已驳回',
            content: "任务「{$this->taskName}」的文件已被驳回" . ($this->reason ? "：{$this->reason}" : ''),
            relatedType: 'task',
            relatedId: $this->taskId,
            senderId: $this->senderId,
        );
    }
}
