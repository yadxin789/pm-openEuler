<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\ProposesChanges;
use App\Models\AiChangeProposal;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 任务类变更提案：新建 / 修改 / 申请延期。
 *
 * 只生成提案，不直接写库。权限以当前登录用户为准。
 */
class ProposeTaskChange implements Tool
{
    use ProposesChanges;

    public function description(): Stringable|string
    {
        return '当用户明确要求新建任务、修改任务（名称/执行人/审核人/起止日期）或为任务申请延期时，'
            . '调用本工具生成一条「待确认变更提案」。本工具不会直接修改数据，需用户在 AI 授权中心确认后才生效。'
            . '必须先确认用户确实提出了该变更请求，不得自行臆测。';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->currentUser();
        if (!$user) {
            return '无法识别当前用户，暂不能提交变更提案。';
        }

        $action = (string) ($request['action'] ?? '');
        $reason = $request['reason'] ?? null;

        if (!in_array($action, ['create', 'update', 'extension'], true)) {
            return 'action 必须是 create（新建）/ update（修改）/ extension（延期）之一。';
        }

        if ($action === 'create') {
            return $this->handleCreate($user, $request, $reason);
        }

        $taskId = (int) ($request['task_id'] ?? 0);
        $task = Task::find($taskId);
        if (!$task) {
            return "未找到任务 ID {$taskId}。";
        }

        if ($action === 'extension') {
            $days = (int) ($request['days'] ?? 0);
            if ($days < 1) {
                return '延期天数 days 必须大于 0。';
            }
            if (!$this->delegation()->canApplyExtension($user, $task)) {
                return "你没有为该任务申请延期的权限（仅任务执行人可申请，且任务不能已完成或审核中）。任务：{$task->name}";
            }

            $proposal = $this->submit($user, AiChangeProposal::RESOURCE_TASK, 'extension', [
                'days' => $days,
                'reason' => $reason,
            ], $task);

            return $this->renderProposal($proposal);
        }

        // update
        if (!$this->delegation()->canManageTask($user, $task)) {
            return "你没有编辑该任务的权限（仅管理员 / 项目创建人 / 执行人 / 审核人可编辑）。任务：{$task->name}";
        }

        $payload = [];
        foreach (['name', 'executor_id', 'auditor_id', 'start_date', 'end_date', 'task_type_id', 'department_id'] as $field) {
            if ($request[$field] ?? null) {
                $payload[$field] = $request[$field];
            }
        }
        if (!$payload) {
            return '没有提供任何需要修改的字段（name / executor_id / auditor_id / start_date / end_date）。';
        }
        $payload['reason'] = $reason;

        $proposal = $this->submit($user, AiChangeProposal::RESOURCE_TASK, 'update', $payload, $task);

        return $this->renderProposal($proposal);
    }

    private function handleCreate($user, Request $request, ?string $reason): string
    {
        $projectId = (int) ($request['project_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));

        $project = Project::find($projectId);
        if (!$project) {
            return "未找到项目 ID {$projectId}。";
        }
        if ($name === '') {
            return '新建任务必须提供任务名称 name。';
        }
        if (!$this->delegation()->canCreateTask($user, $project)) {
            return "你没有在「{$project->name}」下新建任务的权限（仅项目创建人或管理员）。";
        }

        $payload = [
            'project_id' => $project->id,
            'name' => $name,
        ];
        foreach (['department_id', 'task_type_id', 'executor_id', 'auditor_id', 'start_date', 'end_date'] as $field) {
            if ($request[$field] ?? null) {
                $payload[$field] = $request[$field];
            }
        }
        $payload['reason'] = $reason;

        try {
            $payload = $this->proposals()->normalizeTaskCreate($payload, $project);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage() . '请先向用户确认执行人与审核人。';
        }

        $proposal = $this->submit($user, AiChangeProposal::RESOURCE_TASK, 'create', $payload);

        return $this->renderProposal($proposal);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required()->enum(['create', 'update', 'extension'])
                ->description('create=新建任务，update=修改任务，extension=申请延期'),
            'task_id' => $schema->integer()->description('目标任务 ID；update / extension 必填'),
            'project_id' => $schema->integer()->description('目标项目 ID；create 必填'),
            'name' => $schema->string()->description('任务名称；新建时必填，修改时选填'),
            'department_id' => $schema->integer()->description('负责部门 ID'),
            'task_type_id' => $schema->integer()->description('任务类型 ID'),
            'executor_id' => $schema->integer()->description('执行人用户 ID；新建时必填，可用 QueryUsers 查询'),
            'auditor_id' => $schema->integer()->description('审核人用户 ID；新建时必填，且不能与执行人相同'),
            'start_date' => $schema->string()->description('开始日期 YYYY-MM-DD；省略则取项目开始日期'),
            'end_date' => $schema->string()->description('截止日期 YYYY-MM-DD；省略则取项目结束日期'),
            'days' => $schema->integer()->min(1)->description('延期天数；extension 必填'),
            'reason' => $schema->string()->description('变更理由，会展示给审批人，建议填写'),
        ];
    }
}
