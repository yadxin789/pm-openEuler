<?php

namespace App\Http\Controllers;

use App\Models\AiChangeProposal;
use App\Models\AiDelegationToken;
use App\Services\AiChangeProposalService;
use App\Services\AiDelegationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AI 授权中心 —— 用户侧界面。
 *
 * 在这里用户可以：
 *   1) 签发 / 吊销 委托令牌（决定 AI 能以「我的身份」做什么）
 *   2) 审阅 AI 提交的变更提案 diff，并确认或驳回
 */
class AiDelegationController extends Controller
{
    public function __construct(
        protected AiDelegationService $delegation,
        protected AiChangeProposalService $proposals
    ) {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();
        $this->proposals->expireStale();

        $tokens = AiDelegationToken::where('user_id', $user->id)
            ->latest()
            ->get();

        $pending = AiChangeProposal::where('user_id', $user->id)
            ->where('status', AiChangeProposal::STATUS_PENDING)
            ->latest()
            ->get();

        $history = AiChangeProposal::where('user_id', $user->id)
            ->where('status', '!=', AiChangeProposal::STATUS_PENDING)
            ->latest()
            ->limit(20)
            ->get();

        $scopeOptions = AiDelegationToken::scopeOptions();

        return view('ai-delegation.index', compact('tokens', 'pending', 'history', 'scopeOptions'));
    }

    /**
     * 签发委托令牌。明文只在本次响应中展示一次。
     */
    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'in:' . implode(',', array_keys(AiDelegationToken::scopeOptions())),
            'days' => 'nullable|integer|min:1|max:730',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:600',
        ]);

        [$token, $plain] = AiDelegationToken::issue(
            Auth::user(),
            $data['name'],
            $data['scopes'],
            (int) ($data['days'] ?? 30),
            (int) ($data['rate_limit_per_minute'] ?? 60)
        );

        return redirect()->route('ai-delegation.index')
            ->with('success', '委托令牌已签发')
            ->with('new_token', $plain);
    }

    public function revokeToken(AiDelegationToken $token): RedirectResponse
    {
        if ($token->user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            abort(403, '无权操作该令牌');
        }

        $token->update(['revoked_at' => now()]);

        return redirect()->route('ai-delegation.index')->with('success', '令牌已吊销，AI 将立即失去对应授权');
    }

    public function confirmProposal(Request $request, AiChangeProposal $proposal): RedirectResponse
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        // 执行期间以审批人身份运行，保证状态流转日志 / 通知归属到真人
        Auth::setUser(Auth::user());

        $result = $this->proposals->confirm($proposal, Auth::user(), $request->input('note'));

        return redirect()->route('ai-delegation.index')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? '变更已确认并执行' : $result['message']
        );
    }

    public function rejectProposal(Request $request, AiChangeProposal $proposal): RedirectResponse
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        $result = $this->proposals->reject($proposal, Auth::user(), $request->input('note'));

        return redirect()->route('ai-delegation.index')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? '变更已驳回，业务数据未被修改' : $result['message']
        );
    }
}
