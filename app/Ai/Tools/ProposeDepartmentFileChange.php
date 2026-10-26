<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\ProposesChanges;
use App\Models\AiChangeProposal;
use App\Models\DepartmentFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 支援文件（部门资料文件）变更提案：修改分类/可见范围、删除。
 *
 * 注意：替换文件内容需要真实的二进制上传，内置 AI 聊天场景不支持，
 * 请引导用户在「支援中心」操作，或由外部 Agent 走 /api/ai/write/department-files 接口。
 */
class ProposeDepartmentFileChange implements Tool
{
    use ProposesChanges;

    public function description(): Stringable|string
    {
        return '当用户明确要求调整支援文件（部门资料文件）的分类、可见范围，或删除某个支援文件时，'
            . '调用本工具生成「待确认变更提案」。本工具不会直接改动文件，需用户本人在 AI 授权中心确认后才生效。'
            . '仅管理员或上传人本人有权发起；本工具不能替换文件内容。';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->currentUser();
        if (!$user) {
            return '无法识别当前用户，暂不能提交变更提案。';
        }

        $action = (string) ($request['action'] ?? '');
        $reason = $request['reason'] ?? null;
        $fileId = (int) ($request['file_id'] ?? 0);

        if (!in_array($action, ['update', 'delete'], true)) {
            return 'action 仅支持 update（改分类 / 可见范围）或 delete（删除）。替换文件内容请在「支援中心」手动操作。';
        }

        $file = DepartmentFile::find($fileId);
        if (!$file) {
            return "未找到支援文件 ID {$fileId}。";
        }
        if (!$this->delegation()->canWriteDepartmentFile($user, $file)) {
            return "你没有操作支援文件「{$file->original_name}」的权限（仅管理员或上传人本人）。";
        }

        if ($action === 'delete') {
            $proposal = $this->submit($user, AiChangeProposal::RESOURCE_DEPARTMENT_FILE, 'delete', [
                'reason' => $reason,
            ], $file);

            return $this->renderProposal($proposal);
        }

        $payload = [];
        if (($request['category'] ?? null) !== null) {
            $payload['category'] = $request['category'];
        }
        if (($request['visibility'] ?? null) !== null) {
            $payload['visibility'] = $request['visibility'];
        }
        if (!$payload) {
            return '没有提供任何需要修改的字段（category / visibility）。';
        }
        $payload['reason'] = $reason;

        $proposal = $this->submit($user, AiChangeProposal::RESOURCE_DEPARTMENT_FILE, 'update', $payload, $file);

        return $this->renderProposal($proposal);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required()->enum(['update', 'delete']),
            'file_id' => $schema->integer()->required()->description('目标支援文件 ID'),
            'category' => $schema->string()->enum(array_keys(DepartmentFile::categoryOptions()))
                ->description('资料类型：template 模板 / example 范例 / product_spec 产品规格书 等'),
            'visibility' => $schema->string()->enum(array_keys(DepartmentFile::visibilityOptions()))
                ->description('可见范围：all 全员 / department 本部门 / private 仅本人'),
            'reason' => $schema->string()->description('变更理由，会展示给审批人'),
        ];
    }
}
