<?php

namespace App\Ai\Tools;

use App\Models\AiChangeProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 查询当前用户名下由 AI 提交的变更提案及其状态（只读）。
 *
 * 刻意不提供「确认提案」工具 —— 审批权必须留在人手里，由用户在 AI 授权中心操作。
 */
class ListMyProposals implements Tool
{
    public function description(): Stringable|string
    {
        return '查询当前用户名下由 AI 提交的变更提案列表及处理状态（待确认 / 已执行 / 已驳回 / 已过期 / 执行失败）。'
            . '当用户问「我之前让 AI 改的东西怎么样了」「有哪些待我确认的变更」时使用。';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = auth()->user();
        if (!$user) {
            return '无法识别当前用户。';
        }

        app(\App\Services\AiChangeProposalService::class)->expireStale();

        $query = AiChangeProposal::where('user_id', $user->id)->latest();
        if ($status = $request['status'] ?? null) {
            $query->where('status', $status);
        }
        $proposals = $query->limit((int) ($request['limit'] ?? 10))->get();

        if ($proposals->isEmpty()) {
            return '当前没有任何 AI 变更提案记录。';
        }

        $pending = $proposals->where('status', AiChangeProposal::STATUS_PENDING)->count();
        $out = "共 {$proposals->count()} 条提案" . ($pending ? "，其中 {$pending} 条待你确认" : '') . "：\n";

        foreach ($proposals as $p) {
            $out .= sprintf(
                "- #%d [%s] %s / %s，状态：%s，提交于 %s\n",
                $p->id,
                $p->resourceLabel(),
                $p->actionLabel(),
                $p->resource_id ? 'ID:' . $p->resource_id : '新增',
                $this->proposalStatusLabel($p->status),
                $p->created_at?->format('m-d H:i')
            );
            if ($p->isPending()) {
                $out .= '    待确认，请在 ' . url('/ai-delegation') . ' 审阅\n';
            } elseif ($p->resolve_note) {
                $out .= "    批注：{$p->resolve_note}\n";
            }
        }

        return rtrim($out);
    }

    private function proposalStatusLabel(string $status): string
    {
        return match ($status) {
            AiChangeProposal::STATUS_PENDING => '待确认',
            AiChangeProposal::STATUS_CONFIRMED => '已执行',
            AiChangeProposal::STATUS_REJECTED => '已驳回',
            AiChangeProposal::STATUS_EXPIRED => '已过期',
            AiChangeProposal::STATUS_FAILED => '执行失败',
            default => $status,
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([
                AiChangeProposal::STATUS_PENDING,
                AiChangeProposal::STATUS_CONFIRMED,
                AiChangeProposal::STATUS_REJECTED,
                AiChangeProposal::STATUS_EXPIRED,
                AiChangeProposal::STATUS_FAILED,
            ])->description('按状态过滤，不传则返回全部'),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
