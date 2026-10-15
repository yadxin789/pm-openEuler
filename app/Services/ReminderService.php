<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\OverdueWarningNotification;
use App\Notifications\TaskDueNotification;
use App\States\Project\InProgress as ProjectInProgress;
use App\States\Project\Overdue as ProjectOverdue;
use App\States\Task\Completed as TaskCompleted;
use App\States\Task\ForAudit;
use App\States\Task\Overdue as TaskOverdue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 定时提醒服务：任务到期提醒、任务逾期检查、审核超时提醒、项目逾期标记。
 *
 * 这些逻辑原先定义在 app/Console/Kernel.php 中，但本项目为 Laravel 11+ 结构，
 * 框架不会加载 App\Console\Kernel，导致它们从未执行过。
 * 现已迁移至本服务，并由 routes/console.php 统一调度。
 */
class ReminderService
{
    /**
     * 检查未来 3 天内到期的任务，发送提醒通知。
     */
    public function checkUpcomingDeadlines(): void
    {
        $now = Carbon::now();
        $threeDaysLater = $now->copy()->addDays(3);

        $tasks = Task::whereBetween('end_date', [$now->toDateString(), $threeDaysLater->toDateString()])
            ->where('status', '!=', TaskCompleted::class)
            ->whereNotIn('status', [TaskOverdue::class])
            ->with(['executor', 'project'])
            ->get();

        $notified = 0;

        foreach ($tasks as $task) {
            // 所属项目已进回收站的任务不再提醒
            if (!$task->project) {
                continue;
            }

            $daysLeft = max(0, (int) $now->diffInDays($task->end_date, false));

            // 通知执行人
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

            $notified++;
        }

        Log::info("到期提醒：已为 {$notified} 个任务发送到期提醒", [
            'check_time' => $now->toDateTimeString(),
            'upcoming_tasks' => $tasks->count(),
        ]);
    }

    /**
     * 检查逾期的任务并自动更新状态。
     */
    public function checkOverdueTasks(): void
    {
        $now = Carbon::now();

        $overdueTasks = Task::where('end_date', '<', $now->toDateString())
            ->where('status', '!=', TaskCompleted::class)
            ->where('status', '!=', TaskOverdue::class)
            ->with(['executor', 'auditor', 'project'])
            ->get();

        $handled = 0;

        foreach ($overdueTasks as $task) {
            // 所属项目已进回收站的任务不再处理
            if (!$task->project) {
                continue;
            }

            try {
                $task->is_overdue = true;
                $task->status->transitionTo(TaskOverdue::class);
                $task->save();

                // 通知执行人
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
                            content: "您负责审核的任务「{$task->name}」（项目：{$task->project->name}）已逾期，执行人：" . ($executor?->name ?? '未知'),
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
                            content: "项目「{$task->project->name}」的任务「{$task->name}」已逾期，执行人：" . ($executor?->name ?? '未知'),
                            relatedType: 'task',
                            relatedId: $task->id,
                        ));
                    }
                }

                $handled++;
            } catch (\Exception $e) {
                Log::error("任务逾期处理失败: {$task->id}", [
                    'error' => $e->getMessage(),
                    'task' => $task->name,
                ]);
            }
        }

        Log::info("逾期检查：发现 {$overdueTasks->count()} 个新逾期任务，处理成功 {$handled} 个", [
            'check_time' => $now->toDateTimeString(),
        ]);
    }

    /**
     * 检查审核超时（审核超过 3 天未处理）。
     */
    public function checkAuditOverdue(): void
    {
        $now = Carbon::now();
        $threeDaysAgo = $now->copy()->subDays(3);

        $auditOverdueTasks = Task::where('status', ForAudit::class)
            ->where('updated_at', '<', $threeDaysAgo)
            ->with(['auditor', 'executor', 'project'])
            ->get();

        $notified = 0;

        foreach ($auditOverdueTasks as $task) {
            // 所属项目已进回收站的任务不再提醒
            if (!$task->project) {
                continue;
            }

            // 通知审核人
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
                        content: "项目「{$task->project->name}」的任务「{$task->name}」审核超时，审核人：" . ($auditor?->name ?? '未知'),
                        relatedType: 'task',
                        relatedId: $task->id,
                        notificationType: 'audit_overdue',
                    ));
                }
            }

            $notified++;
        }

        Log::info("审核超时检查：发现 {$auditOverdueTasks->count()} 个审核超时任务", [
            'check_time' => $now->toDateTimeString(),
        ]);
    }

    /**
     * 检查项目逾期并自动更新状态。
     */
    public function checkOverdueProjects(): void
    {
        $now = Carbon::now();

        $overdueProjects = Project::where('end_date', '<', $now->toDateString())
            ->where('status', ProjectInProgress::class)
            ->where('progress', '<', 100)
            ->with(['creator'])
            ->get();

        $handled = 0;

        foreach ($overdueProjects as $project) {
            try {
                $project->status->transitionTo(ProjectOverdue::class);
                $project->save();

                // 通知项目创建人
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
                foreach ($project->participants() as $user) {
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

                $handled++;
            } catch (\Exception $e) {
                Log::error("项目逾期处理失败: {$project->id}", [
                    'error' => $e->getMessage(),
                    'project' => $project->name,
                ]);
            }
        }

        Log::info("项目逾期检查：发现 {$overdueProjects->count()} 个逾期项目，处理成功 {$handled} 个", [
            'check_time' => $now->toDateTimeString(),
        ]);
    }
}
