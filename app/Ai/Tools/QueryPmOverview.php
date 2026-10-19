<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\PmFormats;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Department;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryPmOverview implements Tool
{
    use PmFormats;

    public function description(): Stringable|string
    {
        return '获取 PM 系统经营概览：项目总数、任务总数、进行中/逾期/待审核/已逾期任务数、用户数、部门数等统计。';
    }

    public function handle(Request $request): Stringable|string
    {
        $projectTotal = Project::count();
        $projectInProgress = Project::where('status', \App\States\Project\InProgress::class)->count();
        $projectOverdue = Project::where('status', \App\States\Project\Overdue::class)->count();
        $projectCompleted = Project::where('status', \App\States\Project\Completed::class)->count();
        $projectSuspended = Project::where('status', \App\States\Project\Suspended::class)->count();

        $taskTotal = Task::count();
        $taskPending = Task::where('status', \App\States\Task\Pending::class)->count();
        $taskInProgress = Task::where('status', \App\States\Task\InProgress::class)->count();
        $taskForAudit = Task::where('status', \App\States\Task\ForAudit::class)->count();
        $taskOverdue = Task::where('status', \App\States\Task\Overdue::class)->count();
        $taskCompleted = Task::whereIn('status', [
            \App\States\Task\Completed::class,
            \App\States\Task\OverdueCompleted::class,
        ])->count();

        return "PM 系统概览：\n"
            . "- 项目：共 {$projectTotal} 个（进行中 {$projectInProgress}，逾期 {$projectOverdue}，已完成 {$projectCompleted}，已暂停 {$projectSuspended}）\n"
            . "- 任务：共 {$taskTotal} 个（待开始 {$taskPending}，进行中 {$taskInProgress}，待审核 {$taskForAudit}，已逾期 {$taskOverdue}，已完成 {$taskCompleted}）\n"
            . "- 用户：" . User::count() . " 人，部门：" . Department::count() . " 个";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
