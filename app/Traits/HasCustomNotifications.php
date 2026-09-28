<?php

namespace App\Traits;

use App\Models\Notification as NotificationModel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;

/**
 * 自定义通知 trait
 * 将 Laravel Notification 系统与我们自定义的 notifications 表桥接
 */
trait HasCustomNotifications
{
    use Notifiable;

    /**
     * 将 Laravel Notification 路由到自定义 notifications 表
     */
    public function routeNotificationForDatabase(): array
    {
        // 返回空数组，因为我们手动处理 database 通知的存储
        return [];
    }

    /**
     * 自定义 database 通道的发送逻辑
     * 覆盖 Notifiable trait 中的默认行为
     */
    public function notify(Notification $notification): void
    {
        // 使用队列调度（如果 Notification 实现了 ShouldQueue）
        if (method_exists($notification, 'shouldQueue') && $notification->shouldQueue()) {
            dispatch(function () use ($notification) {
                $this->sendNotificationNow($notification);
            });
        } else {
            $this->sendNotificationNow($notification);
        }
    }

    /**
     * 立即发送通知并保存到自定义 notifications 表
     */
    protected function sendNotificationNow(Notification $notification): void
    {
        if (method_exists($notification, 'toDatabase')) {
            $data = $notification->toDatabase($this);

            NotificationModel::create([
                'user_id' => $this->id,
                'sender_id' => $data['sender_id'] ?? null,
                'type' => $data['type'] ?? 'system',
                'title' => $data['title'] ?? '',
                'content' => $data['content'] ?? '',
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
            ]);
        }
    }
}
