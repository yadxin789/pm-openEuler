<?php

use App\Services\ReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 定时任务注册（本项目唯一生效的调度入口）
|--------------------------------------------------------------------------
| 服务器 crontab 每分钟执行一次 `php artisan schedule:run`，
| 所有调度必须写在此文件，不要写进 app/Console/Kernel.php（框架不会加载）。
*/

// 每天凌晨 0 点自动刷新项目状态、任务状态、进度和统计数据
Schedule::command('projects:refresh-status')->dailyAt('00:00');

// 每天凌晨 3:30 清理回收站中超过保留期限的内容（彻底删除，含物理文件与历史快照）
Schedule::command('trash:purge-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| 以下 4 个调度原先写在 app/Console/Kernel.php 中，
| 因 Laravel 11+ 不加载该类而从未执行，现迁移至此并启用。
|--------------------------------------------------------------------------
*/

// 任务到期提醒：每天早上 8:00 检查未来 3 天内到期的任务并通知执行人
Schedule::call([ReminderService::class, 'checkUpcomingDeadlines'])
    ->name('reminder:upcoming-deadlines')
    ->dailyAt('08:00');

// 任务逾期检查：每 30 分钟检查是否有新逾期的任务
// 注意：闭包/callable 类型的调度必须先 name() 才能 withoutOverlapping()，否则会抛 LogicException
Schedule::call([ReminderService::class, 'checkOverdueTasks'])
    ->name('reminder:overdue-tasks')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// 审核超时提醒：每天上午 10:00 和下午 15:00 检查审核超时的任务
Schedule::call([ReminderService::class, 'checkAuditOverdue'])
    ->name('reminder:audit-overdue')
    ->twiceDaily(10, 15);

// 项目逾期检查：每天凌晨 1:00 检查项目是否逾期
Schedule::call([ReminderService::class, 'checkOverdueProjects'])
    ->name('reminder:overdue-projects')
    ->dailyAt('01:00');
