<?php

namespace App\Notifications;

class SystemNotification extends BaseNotification
{
    public function __construct(
        public string $title,
        public string $content,
        public ?string $relatedType = null,
        public ?int $relatedId = null,
        public ?int $senderId = null,
        public string $type = 'system',
    ) {}

    public function toDatabase(object $notifiable): array
    {
        return $this->buildData(
            type: $this->type,
            title: $this->title,
            content: $this->content,
            relatedType: $this->relatedType,
            relatedId: $this->relatedId,
            senderId: $this->senderId,
        );
    }
}
