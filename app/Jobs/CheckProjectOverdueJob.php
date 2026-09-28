<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Project;
use App\Models\User;
use App\Notifications\OverdueWarningNotification;
use App\States\Project\Overdue as ProjectOverdue;
use App\States\Project\InProgress as ProjectInProgress;
use Carbon\Carbon;

/**
 * 检查项目逾期的队列任务
 */
class CheckProjectOverdueJob implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    public function handle(): void
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
                \Log::error("CheckProjectOverdueJob 失败: {$project->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
