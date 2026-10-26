<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\ProposesChanges;
use App\Models\AiChangeProposal;
use App\Models\Knowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 知识库变更提案：新建 / 修改 / 删除。
 * 权限复用 Knowledge::isEditableBy —— 只有作者本人或管理员能发起。
 */
class ProposeKnowledgeChange implements Tool
{
    use ProposesChanges;

    public function description(): Stringable|string
    {
        return '当用户明确要求新建、修改或删除知识库条目时，调用本工具生成「待确认变更提案」。'
            . '本工具不会直接改动知识库，需用户本人在 AI 授权中心确认后才生效。'
            . '仅知识库作者本人或管理员有权发起。';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->currentUser();
        if (!$user) {
            return '无法识别当前用户，暂不能提交变更提案。';
        }

        $action = (string) ($request['action'] ?? '');
        $reason = $request['reason'] ?? null;

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            return 'action 必须是 create（新建）/ update（修改）/ delete（删除）之一。';
        }

        if ($action === 'create') {
            $title = trim((string) ($request['title'] ?? ''));
            if ($title === '') {
                return '新建知识库必须提供标题 title。';
            }

            $payload = [
                'title' => $title,
                'desc' => $request['desc'] ?? null,
                'cate_id' => $request['cate_id'] ?? null,
                'is_share' => (bool) ($request['is_share'] ?? false),
                'reason' => $reason,
            ];

            $proposal = $this->submit($user, AiChangeProposal::RESOURCE_KNOWLEDGE, 'create', $payload);

            return $this->renderProposal($proposal);
        }

        $knowledgeId = (int) ($request['knowledge_id'] ?? 0);
        $knowledge = Knowledge::find($knowledgeId);
        if (!$knowledge) {
            return "未找到知识库 ID {$knowledgeId}。";
        }
        if (!$this->delegation()->canWriteKnowledge($user, $knowledge)) {
            return "你没有编辑知识库「{$knowledge->title}」的权限（仅作者本人或管理员）。";
        }

        if ($action === 'delete') {
            if ($knowledge->docs()->count() > 0) {
                return "知识库「{$knowledge->title}」下仍有 {$knowledge->docs()->count()} 篇文档，请先清空文档再删除。";
            }
            $proposal = $this->submit($user, AiChangeProposal::RESOURCE_KNOWLEDGE, 'delete', [
                'reason' => $reason,
            ], $knowledge);

            return $this->renderProposal($proposal);
        }

        $payload = [];
        foreach (['title', 'desc', 'cate_id'] as $field) {
            if (($request[$field] ?? null) !== null) {
                $payload[$field] = $request[$field];
            }
        }
        if (($request['is_share'] ?? null) !== null) {
            $payload['is_share'] = (bool) $request['is_share'];
        }
        if (!$payload) {
            return '没有提供任何需要修改的字段（title / desc / cate_id / is_share）。';
        }
        $payload['reason'] = $reason;

        $proposal = $this->submit($user, AiChangeProposal::RESOURCE_KNOWLEDGE, 'update', $payload, $knowledge);

        return $this->renderProposal($proposal);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required()->enum(['create', 'update', 'delete']),
            'knowledge_id' => $schema->integer()->description('目标知识库 ID；update / delete 必填'),
            'title' => $schema->string()->description('标题；新建时必填'),
            'desc' => $schema->string()->description('简介'),
            'cate_id' => $schema->integer()->description('分类 ID'),
            'is_share' => $schema->boolean()->description('是否共享给全员'),
            'reason' => $schema->string()->description('变更理由，会展示给审批人'),
        ];
    }
}
