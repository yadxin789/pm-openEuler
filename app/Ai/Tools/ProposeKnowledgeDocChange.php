<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\ProposesChanges;
use App\Models\AiChangeProposal;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 知识库文档（含章节、链接）变更提案：新建 / 修改 / 删除。
 *
 * 这是内置 AI「把回答沉淀进知识库」的落点：AI 起草正文 -> 人工确认 -> 入库。
 */
class ProposeKnowledgeDocChange implements Tool
{
    use ProposesChanges;

    public function description(): Stringable|string
    {
        return '当用户明确要求新建、修改或删除知识库中的文档/章节/链接时，调用本工具生成「待确认变更提案」。'
            . '可直接提交 Markdown 正文（md_content），但不会直接入库，需用户本人在 AI 授权中心确认后才生效。'
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
            $knowledgeId = (int) ($request['knowledge_id'] ?? 0);
            $knowledge = Knowledge::find($knowledgeId);
            if (!$knowledge) {
                return "未找到知识库 ID {$knowledgeId}。";
            }
            if (!$this->delegation()->canWriteKnowledge($user, $knowledge)) {
                return "你没有在知识库「{$knowledge->title}」下新建文档的权限（仅作者本人或管理员）。";
            }

            $title = trim((string) ($request['title'] ?? ''));
            if ($title === '') {
                return '新建文档必须提供标题 title。';
            }

            $payload = [
                'knowledge_id' => $knowledge->id,
                'title' => $title,
                'type' => (int) ($request['type'] ?? KnowledgeDoc::TYPE_DOC),
                'pid' => (int) ($request['pid'] ?? 0),
                'md_content' => $request['md_content'] ?? null,
                'link' => $request['link'] ?? null,
                'reason' => $reason,
            ];

            $proposal = $this->submit($user, AiChangeProposal::RESOURCE_KNOWLEDGE_DOC, 'create', $payload);

            return $this->renderProposal($proposal);
        }

        $docId = (int) ($request['doc_id'] ?? 0);
        $doc = KnowledgeDoc::find($docId);
        if (!$doc) {
            return "未找到文档 ID {$docId}。";
        }
        if (!$this->delegation()->canWriteKnowledgeDoc($user, $doc)) {
            return "你没有编辑文档「{$doc->title}」的权限（仅所属知识库作者或管理员）。";
        }

        if ($action === 'delete') {
            $proposal = $this->submit($user, AiChangeProposal::RESOURCE_KNOWLEDGE_DOC, 'delete', [
                'reason' => $reason,
            ], $doc);

            return $this->renderProposal($proposal);
        }

        $payload = [];
        foreach (['title', 'md_content', 'link', 'type', 'pid', 'sort'] as $field) {
            if (($request[$field] ?? null) !== null) {
                $payload[$field] = $request[$field];
            }
        }
        if (!$payload) {
            return '没有提供任何需要修改的字段（title / md_content / link / type / pid / sort）。';
        }
        $payload['reason'] = $reason;

        $proposal = $this->submit($user, AiChangeProposal::RESOURCE_KNOWLEDGE_DOC, 'update', $payload, $doc);

        return $this->renderProposal($proposal);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required()->enum(['create', 'update', 'delete']),
            'knowledge_id' => $schema->integer()->description('所属知识库 ID；create 必填'),
            'doc_id' => $schema->integer()->description('目标文档 ID；update / delete 必填'),
            'title' => $schema->string()->description('文档标题；新建时必填'),
            'type' => $schema->integer()->enum([1, 2, 3])->description('1=文档，2=章节，3=外部链接'),
            'pid' => $schema->integer()->description('父节点 ID，0 表示顶层'),
            'md_content' => $schema->string()->description('Markdown 正文'),
            'link' => $schema->string()->description('外部链接地址；type=3 时使用'),
            'sort' => $schema->integer()->description('排序值'),
            'reason' => $schema->string()->description('变更理由，会展示给审批人'),
        ];
    }
}
