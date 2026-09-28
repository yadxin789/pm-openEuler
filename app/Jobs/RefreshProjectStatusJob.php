<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 刷新项目状态的队列任务
 * 调用 artisan 命令来刷新所有项目状态
 */
class RefreshProjectStatusJob implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    public function handle(): void
    {
        \Artisan::call('projects:refresh-status');
    }
}
