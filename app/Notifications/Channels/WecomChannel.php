<?php

namespace App\Notifications\Channels;

use App\Services\WecomNotificationService;
use Illuminate\Notifications\Notification;

class WecomChannel
{
    protected WecomNotificationService $service;

    public function __construct(WecomNotificationService $service)
    {
        $this->service = $service;
    }

    /**
     * 通过企业微信发送通知
     *
     * 通知类需实现 toWecom() 方法，返回：
     * [
     *     'type' => 'task_assigned',  // 对应 wecom.templates 的 key
     *     'data' => [...],            // 模板变量
     *     'url'  => 'https://...',    // 点击跳转链接（可选）
     * ]
     */
    public function send($notifiable, Notification $notification): void
    {
        if (!$notifiable->wecom_userid) {
            return;
        }

        if (method_exists($notification, 'toWecom')) {
            $payload = $notification->toWecom($notifiable);

            if (is_array($payload) && isset($payload['type'])) {
                $this->service->send(
                    $notifiable,
                    $payload['type'],
                    $payload['data'] ?? [],
                    $payload['url'] ?? ''
                );
            }
        }
    }
}
