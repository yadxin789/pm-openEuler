<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * 所有通知的基类
 * 使用 database 通道存储通知
 * 实现 ShouldQueue 接口以确保异步队列发送
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * 获取通知发送通道
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // 如果用户绑定了企业微信且启用了通知，则同时通过企微通道发送
        if (!empty($notifiable->wecom_userid) && $notifiable->wecom_notify_enabled) {
            $channels[] = 'wecom';
        }

        return $channels;
    }

    /**
     * 序列化为 database 通知格式
     * 与现有 notifications 表结构兼容
     */
    abstract public function toDatabase(object $notifiable): array;

    /**
     * 构建 database 通知数据
     */
    protected function buildData(string $type, string $title, string $content, ?string $relatedType = null, ?int $relatedId = null, ?int $senderId = null): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'sender_id' => $senderId,
        ];
    }
}
