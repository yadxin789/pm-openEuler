<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\Project;
use App\Models\Task;
use App\Models\Todo;
use App\Models\User;
use App\States\Task\TaskCompleted;
use App\States\Task\Overdue as TaskOverdue;
use App\States\Project\Overdue as ProjectOverdue;
use App\States\Project\InProgress as ProjectInProgress;
use App\States\Project\Suspended;
use App\States\Project\Completed as ProjectCompleted;
use App\States\Task\ForAudit;
use App\States\Task\Pending;
use App\Notifications\TaskDueNotification;
use App\Notifications\OverdueWarningNotification;
use App\Notifications\SystemNotification;
use Carbon\Carbon;

class Kernel extends ConsoleKernel
{
    /**
     * 定义应用的命令调度
     */
    protected function schedule(Schedule $schedule): void
    {
        // ========== 项目状态自动刷新 ==========
        // 每 3 秒刷新一次所有项目的状态和进度
        $schedule->command('projects:refresh-status')
            ->everyThreeSeconds()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler-refresh.log'));

        // ========== 任务到期提醒 ==========
        // 每天早上 8:00 检查未来 3 天内到期的任务并通知执行人
        $schedule->call(function () {
            $this->checkUpcomingDeadlines();
        })
            ->dailyAt('08:00')
            ->appendOutputTo(storage_path('logs/scheduler-deadline-reminder.log'));

        // ========== 任务逾期检查 ==========
        // 每 30 分钟检查是否有新逾期的任务
        $schedule->call(function () {
            $this->checkOverdueTasks();
        })
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler-overdue.log'));

        // ========== 审核超时提醒 ==========
        // 每天上午 10:00 和下午 15:00 检查审核超时的任务
        $schedule->call(function () {
            $this->checkAuditOverdue();
        })
            ->twiceDaily(10, 15)
            ->appendOutputTo(storage_path('logs/scheduler-audit-reminder.log'));

        // ========== 项目逾期检查 ==========
        // 每天凌晨检查项目是否逾期
        $schedule->call(function () {
            $this->checkOverdueProjects();
        })
            ->dailyAt('01:00')
            ->appendOutputTo(storage_path('logs/scheduler-project-overdue.log'));

        // ========== 队列监控（可选：定期重启队列防止内存泄漏） ==========
        // $schedule->command('queue:restart')
        //     ->everySixHours();
    }

    /**
     * 注册命令
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    /**
     * 检查未来 3 天内到期的任务，发送提醒通知
     */
    private function checkUpcomingDeadlines(): void
    {
        $now = Carbon::now();
        $threeDaysLater = $now->copy()->addDays(3);

        $tasks = Task::whereBetween('end_date', [$now->toDateString(), $threeDaysLater->toDateString()])
            ->where('status', '!=', TaskCompleted::class)
            ->whereNotIn('status', [TaskOverdue::class])
            ->with(['executor', 'project'])
            ->get();

        foreach ($tasks as $task) {
            $daysLeft = max(0, $now->diffInDays($task->end_date, false));
            $urgencyText = $daysLeft === 0 ? '今天' : "还剩 {$daysLeft} 天";

            // 使用 Laravel Notification 通知执行人
            $executor = User::find($task->executor_id);
            if ($executor) {
                $executor->notify(new TaskDueNotification(
                    taskName: $task->name,
                    projectName: $task->project->name,
                    endDate: $task->end_date->format('Y-m-d'),
                    daysLeft: $daysLeft,
                    taskId: $task->id,
                ));
            }

            // 为执行人创建提醒待办
            Todo::firstOrCreate(
                [
                    'user_id' => $task->executor_id,
                    'related_type' => 'task',
                    'related_id' => $task->id,
                    'type' => 'deadline_reminder',
                ],
                [
                    'title' => "【到期提醒】{$task->project->name} - {$task->name}",
                    'status' => 'pending',
                ]
            );
        }

        \Log::info("到期提醒：已为 {$tasks->count()} 个任务发送到期提醒", [
            'check_time' => $now->toDateTimeString(),
            'upcoming_tasks' => $tasks->count(),
        ]);
    }

    /**
     * 检查逾期的任务并自动更新状态
     */
    private function checkOverdueTasks(): void
    {
        $now = Carbon::now();

        $overdueTasks = Task::where('end_date', '<', $now->toDateString())
            ->where('status', '!=', TaskCompleted::class)
            ->where('status', '!=', TaskOverdue::class)
            ->with(['executor', 'auditor', 'project'])
            ->get();

        foreach ($overdueTasks as $task) {
            // 更新任务逾期状态
            try {
                $task->is_overdue = true;
                $task->status->transitionTo(TaskOverdue::class);
                $task->save();

                // 使用 Laravel Notification 通知执行人
                $executor = User::find($task->executor_id);
                if ($executor) {
                    $executor->notify(new OverdueWarningNotification(
                        title: '任务已逾期',
                        content: "任务「{$task->name}」（项目：{$task->project->name}）已逾期！截止日期：{$task->end_date->format('Y-m-d')}，请尽快处理或申请延期。",
                        relatedType: 'task',
                        relatedId: $task->id,
                    ));
                }

                // 通知审核人
                if ($task->auditor_id && $task->auditor_id !== $task->executor_id) {
                    $auditor = User::find($task->auditor_id);
                    if ($auditor) {
                        $auditor->notify(new OverdueWarningNotification(
                            title: '任务已逾期',
                            content: "您负责审核的任务「{$task->name}」（项目：{$task->project->name}）已逾期，执行人：{$task->executor->name}",
                            relatedType: 'task',
                            relatedId: $task->id,
                        ));
                    }
                }

                // 通知项目创建人
                if ($task->project->creator_id && $task->project->creator_id !== $task->executor_id) {
                    $creator = User::find($task->project->creator_id);
                    if ($creator) {
                        $creator->notify(new OverdueWarningNotification(
                            title: '项目任务已逾期',
                            content: "项目「{$task->project->name}」的任务「{$task->name}」已逾期，执行人：{$task->executor->name}",
                            relatedType: 'task',
                            relatedId: $task->id,
                        ));
                    }
                }
            } catch (\Exception $e) {
                \Log::error("任务逾期处理失败: {$task->id}", [
                    'error' => $e->getMessage(),
                    'task' => $task->name,
                ]);
            }
        }

        \Log::info("逾期检查：发现 {$overdueTasks->count()} 个新逾期任务", [
            'check_time' => $now->toDateTimeString(),
        ]);
    }

    /**
     * 检查审核超时（审核超过 3 天未处理）
     */
    private function checkAuditOverdue(): void
    {
        $now = Carbon::now();
        $threeDaysAgo = $now->copy()->subDays(3);

        // 查找待审核超过 3 天的任务
        $auditOverdueTasks = Task::where('status', ForAudit::class)
            ->where('updated_at', '<', $threeDaysAgo)
            ->with(['auditor', 'executor', 'project'])
            ->get();

        foreach ($auditOverdueTasks as $task) {
            // 使用 Laravel Notification 通知审核人
            $auditor = User::find($task->auditor_id);
            if ($auditor) {
                $auditor->notify(new OverdueWarningNotification(
                    title: '审核超时提醒',
                    content: "任务「{$task->name}」（项目：{$task->project->name}）已提交审核超过 3 天，请尽快审核。",
                    relatedType: 'task',
                    relatedId: $task->id,
                    notificationType: 'audit_overdue',
                ));
            }

            // 通知项目创建人
            if ($task->project->creator_id) {
                $creator = User::find($task->project->creator_id);
                if ($creator) {
                    $creator->notify(new OverdueWarningNotification(
                        title: '审核超时提醒',
                        content: "项目「{$task->project->name}」的任务「{$task->name}」审核超时，审核人：{$task->auditor->name}",
                        relatedType: 'task',
                        relatedId: $task->id,
                        notificationType: 'audit_overdue',
                    ));
                }
            }
        }

        \Log::info("审核超时检查：发现 {$auditOverdueTasks->count()} 个审核超时任务", [
            'check_time' => $now->toDateTimeString(),
        ]);
    }

    /**
     * 检查项目逾期并自动更新状态
     */
    private function checkOverdueProjects(): void
    {
        $now = Carbon::now();

        $overdueProjects = Project::where('end_date', '<', $now->toDateString())
            ->where('status', ProjectInProgress::class)
            ->where('progress', '<', 100)
            ->with(['creator'])
            ->get();

        foreach ($overdueProjects as $project) {
            try {
                $project->status->transitionTo(ProjectOverdue::class);
                $project->save();

                // 使用 Laravel Notification 通知项目创建人
                $creator = User::find($project->creator_id);
                if ($creator) {
                    $creator->notify(new OverdueWarningNotification(
                        title: '项目已逾期',
                        content: "项目「{$project->name}」已逾期！截止日期：{$project->end_date->format('Y-m-d')}，当前进度：{$project->progress}%",
                        relatedType: 'project',
                        relatedId: $project->id,
                        notificationType: 'project_overdue',
                    ));
                }

                // 通知所有参与者
                $participants = $project->participants();
                foreach ($participants as $user) {
                    if ($user->id !== $project->creator_id) {
                        $user->notify(new OverdueWarningNotification(
                            title: '项目已逾期',
                            content: "您参与的项目「{$project->name}」已逾期，请尽快完成相关任务。",
                            relatedType: 'project',
                            relatedId: $project->id,
                            notificationType: 'project_overdue',
                        ));
                    }
                }
            } catch (\Exception $e) {
                \Log::error("项目逾期处理失败: {$project->id}", [
                    'error' => $e->getMessage(),
                    'project' => $project->name,
                ]);
            }
        }

        \Log::info("项目逾期检查：发现 {$overdueProjects->count()} 个逾期项目", [
            'check_time' => $now->toDateTimeString(),
        ]);
    }
}
