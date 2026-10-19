<?php

namespace App\Ai\Concerns;

use App\States\Project\Completed as ProjectCompleted;
use App\States\Project\InProgress as ProjectInProgress;
use App\States\Project\NotStarted as ProjectNotStarted;
use App\States\Project\Overdue as ProjectOverdue;
use App\States\Project\OverdueCompleted as ProjectOverdueCompleted;
use App\States\Project\Suspended as ProjectSuspended;
use App\States\Task\Completed as TaskCompleted;
use App\States\Task\ForAudit as TaskForAudit;
use App\States\Task\InProgress as TaskInProgress;
use App\States\Task\Overdue as TaskOverdue;
use App\States\Task\OverdueCompleted as TaskOverdueCompleted;
use App\States\Task\Pending as TaskPending;
use App\States\Task\Rejected as TaskRejected;

trait PmFormats
{
    /**
     * 将状态对象/类名转为可读中文标签
     */
    protected function taskStateLabel(mixed $status): string
    {
        $class = is_string($status) ? $status : get_class($status);

        return match ($class) {
            TaskPending::class => '待开始',
            TaskInProgress::class => '进行中',
            TaskForAudit::class => '待审核',
            TaskCompleted::class => '已完成',
            TaskOverdue::class => '已逾期',
            TaskOverdueCompleted::class => '逾期完成',
            TaskRejected::class => '已驳回',
            default => $class,
        };
    }

    protected function projectStateLabel(mixed $status): string
    {
        $class = is_string($status) ? $status : get_class($status);

        return match ($class) {
            ProjectNotStarted::class => '未开始',
            ProjectInProgress::class => '进行中',
            ProjectOverdue::class => '已逾期',
            ProjectCompleted::class => '已完成',
            ProjectOverdueCompleted::class => '逾期完成',
            ProjectSuspended::class => '已暂停',
            default => $class,
        };
    }
}
