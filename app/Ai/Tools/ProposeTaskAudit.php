<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\ProposesChanges;
use App\Models\AiChangeProposal;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 任务文件审核提案：通过 / 驳回。
 * 只有任务的审核人本人可发起，且仍需其本人在授权中心确认。
 */
class ProposeTaskAudit implements Tool
{
    use ProposesChanges;

    public function description(): Stringable|string
    {
        return '当用户明确要求审核通过或驳回某个任务的交付文件时，调用本工具生成「待确认审核提案」。'
            . '本工具不会直接改变审核状态，需用户本人在 AI 授权中心确认后才生效。'
            . '仅当当前用户是该任务的审核人时才可能成功。';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->currentUser();
        if (!$user) {
            return '无法识别当前用户，暂不能提交审核提案。';
        }

        $taskId = (int) ($request['task_id'] ?? 0);
        $result = (string) ($request['result'] ?? '');
        $reason = $request['reason'] ?? null;

        if (!in_array($result, ['approve', 'reject'], true)) {
            return 'result 必须是 approve（通过）或 reject（驳回）。';
        }

        $task = Task::find($taskId);
        if (!$task) {
            return "未找到任务 ID {$taskId}。";
        }

        if (!$this->delegation()->canAudit($user, $task)) {
            return "你不是任务「{$task->name}」的审核人，或该任务当前不处于可审核状态（需为待审核/已逾期且存在待审文件）。";
        }

        $proposal = $this->submit($user, AiChangeProposal::RESOURCE_FILE, $result, [
            'reason' => $reason,
        ], $task);

        return $this->renderProposal($proposal);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required()->description('目标任务 ID'),
            'result' => $schema->string()->required()->enum(['approve', 'reject'])->description('approve=审核通过，reject=驳回'),
            'reason' => $schema->string()->description('审核意见，驳回时建议填写'),
        ];
    }
}
