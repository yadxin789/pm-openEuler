<?php

namespace App\Ai\Concerns;

use App\Models\AiChangeProposal;
use App\Models\User;
use App\Services\AiChangeProposalService;
use App\Services\AiDelegationService;

/**
 * 内置 AI 写工具的公共能力。
 *
 * 内置 AI 与外部 API 走完全相同的两阶段引擎：
 *   AI 只能生成待确认提案，由当前登录用户（即授权人本人）在「AI 授权中心」确认后才生效。
 * 这里刻意不提供「确认提案」工具 —— 审批权必须留在人手里。
 */
trait ProposesChanges
{
    protected function currentUser(): ?User
    {
        return auth()->user();
    }

    protected function delegation(): AiDelegationService
    {
        return app(AiDelegationService::class);
    }

    protected function proposals(): AiChangeProposalService
    {
        return app(AiChangeProposalService::class);
    }

    /**
     * 提交提案（内部 AI 场景无委托令牌，授权人即当前登录用户）。
     */
    protected function submit(
        User $user,
        string $resourceType,
        string $action,
        array $payload,
        $resource = null
    ): AiChangeProposal {
        $reason = $payload['reason'] ?? null;
        unset($payload['reason']);

        return $this->proposals()->propose(
            $user,
            null,
            $resourceType,
            $action,
            $payload,
            $reason,
            $resource
        );
    }

    /**
     * 把提案渲染成给 AI 阅读的自然语言结果。
     */
    protected function renderProposal(AiChangeProposal $proposal): string
    {
        $out = "已生成变更提案 #{$proposal->id}：{$proposal->resourceLabel()} / {$proposal->actionLabel()}\n";
        $out .= "状态：待确认（我没有直接修改任何数据）\n";
        $out .= "确认地址：" . url('/ai-delegation') . "\n";
        $out .= '有效期至：' . ($proposal->expires_at?->format('Y-m-d H:i') ?? '—') . "\n";

        if ($proposal->reason) {
            $out .= "变更理由：{$proposal->reason}\n";
        }

        $diff = $proposal->diff();
        if ($diff) {
            $out .= "\n变更差异预览：\n";
            foreach ($diff as $field => $change) {
                $out .= '- ' . $field . '：' . $this->fmt($change['before'])
                    . ' → ' . $this->fmt($change['after']) . "\n";
            }
        }

        $out .= "\n请转告用户：需本人登录 PM 系统进入「AI 授权中心」审阅上述差异并确认，确认后变更才会真正生效；也可直接驳回。";

        return $out;
    }

    protected function fmt(mixed $value): string
    {
        if ($value === null) {
            return '（空）';
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $text = (string) $value;

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }
}
