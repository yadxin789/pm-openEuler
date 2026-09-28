<?php

namespace App\Notifications;

class OverdueWarningNotification extends BaseNotification
{
    public function __construct(
        public string $title,
        public string $content,
        public string $relatedType,
        public int $relatedId,
        public string $notificationType = 'overdue_warning',
    ) {}

    public function toDatabase(object $notifiable): array
    {
        return $this->buildData(
            type: $this->notificationType,
            title: $this->title,
            content: $this->content,
            relatedType: $this->relatedType,
            relatedId: $this->relatedId,
        );
    }
}
