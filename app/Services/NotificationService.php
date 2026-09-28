<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use App\Notifications\SystemNotification;

class NotificationService
{
    /**
     * 向特定用户发送通知
     */
    public function sendToUser(User $user, string $title, string $content, string $relatedType, int $relatedId, ?int $senderId = null, string $type = 'info'): void
    {
        $user->notify(new SystemNotification(
            title: $title,
            content: $content,
            relatedType: $relatedType,
            relatedId: $relatedId,
            senderId: $senderId,
            type: $type,
        ));
    }

    /**
     * 向多个用户批量发送通知
     */
    public function sendToUsers(array $userIds, string $title, string $content, string $relatedType, int $relatedId, ?int $senderId = null, string $type = 'info'): void
    {
        $users = User::whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            $this->sendToUser($user, $title, $content, $relatedType, $relatedId, $senderId, $type);
        }
    }

    /**
     * 向所有用户发送通知
     */
    public function sendToAll(string $title, string $content, string $relatedType, int $relatedId, ?int $senderId = null, string $type = 'info'): void
    {
        User::chunk(100, function ($users) use ($title, $content, $relatedType, $relatedId, $senderId, $type) {
            foreach ($users as $user) {
                $this->sendToUser($user, $title, $content, $relatedType, $relatedId, $senderId, $type);
            }
        });
    }

    /**
     * 标记所有通知为已读
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }
}
