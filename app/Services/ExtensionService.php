<?php

namespace App\Services;

use App\Models\ExtensionApplication;
use App\Models\Project;
use App\Models\ProjectLog;
use App\Models\Task;
use App\Models\Todo;
use App\Notifications\ExtensionResultNotification;
use App\States\Task\Overdue;
use App\States\Task\Pending;
use Illuminate\Support\Facades\DB;

class ExtensionService
{
    /**
     * 批准延期申请（TodoController 和 ExtensionController 的共享逻辑）
     * 仅延长该任务的截止日期，不级联后续任务
     */
    public function approve(ExtensionApplication $application): void
    {
        // 检查申请状态，防止重复审批
        if ($application->status !== 'pending') {
            throw new \RuntimeException('该申请已被处理，无法重复操作');
        }

        DB::transaction(function () use ($application) {
            $application->update(['status' => 'approved', 'approved_at' => now()]);

            $task = $application->task;
            $project = $task->project;

            // 仅延长申请任务的截止日期（起始日期不变，不级联后续任务）
            $task->end_date = $task->end_date->addDays($application->days);
            $task->extension_days += $application->days;
            $task->is_overdue = false;
            if ($task->status->equals(Overdue::class)) {
                $task->status->transitionTo(Pending::class);
            }
            $task->save();

            // 删除相关待办
            $this->clearExtensionTodos($application);

            // 发送通知
            $this->notifyApplicant($application, true);

            // 记录日志
            $this->logAction($project, $task, $application, 'extension_approved');

            // 更新项目进度
            $project->calculateProgress();
        });
    }

    /**
     * 驳回延期申请（TodoController 和 ExtensionController 的共享逻辑）
     */
    public function reject(ExtensionApplication $application, ?string $reason = null): void
    {
        // 检查申请状态，防止重复审批
        if ($application->status !== 'pending') {
            throw new \RuntimeException('该申请已被处理，无法重复操作');
        }

        DB::transaction(function () use ($application, $reason) {
            $application->update([
                'status' => 'rejected',
                'reject_reason' => $reason,
            ]);

            // 删除相关待办
            $this->clearExtensionTodos($application);

            // 发送通知
            $this->notifyApplicant($application, false, $reason);
        });
    }

    /**
     * 清理延期申请相关的待办事项
     */
    private function clearExtensionTodos(ExtensionApplication $application): void
    {
        Todo::where('related_type', 'extension_application')
            ->where('related_id', $application->id)
            ->delete();
    }

    /**
     * 通知申请人审批结果
     */
    private function notifyApplicant(ExtensionApplication $application, bool $approved, ?string $reason = null): void
    {
        $applicant = \App\Models\User::find($application->applicant_id);
        if (!$applicant) {
            return;
        }

        $task = $application->task;

        $applicant->notify(new ExtensionResultNotification(
            taskName: $task->name,
            projectName: $task->project->name ?? '',
            taskId: $task->id,
            approved: $approved,
            days: $application->days,
            reason: $reason,
            senderId: auth()->id(),
        ));
    }

    /**
     * 记录项目操作日志
     */
    private function logAction(Project $project, Task $task, ExtensionApplication $application, string $action): void
    {
        ProjectLog::create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'content' => "任务「{$task->name}」延期{$application->days}天",
        ]);
    }
}
