<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\User;

/**
 * 批量发送通知的队列任务
 * 用于向多个用户发送同一条通知（如项目启动、暂停/恢复等）
 */
class SendBulkNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int> $userIds 接收通知的用户 ID 列表
     * @param Notification $notification 要发送的通知实例
     */
    public function __construct(
        public array $userIds,
        public Notification $notification,
    ) {}

    public function handle(): void
    {
        $users = User::whereIn('id', $this->userIds)->get();

        foreach ($users as $user) {
            $user->notify($this->notification);
        }
    }
}
