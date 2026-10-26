<?php

namespace App\Http\Middleware;

use App\Models\AiDelegationToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 数据 API 认证 + 委托身份解析。
 *
 * 两层凭据：
 *   1) X-AI-Key / api_key    —— 系统级客户端密钥（证明"这是已登记的 AI"）
 *   2) X-AI-Token / ai_token —— 用户级委托令牌（证明"这个用户授权了 AI"）
 *
 * 委托令牌一旦校验通过，本次请求的「操作者身份」即绑定为该用户，
 * 后续所有鉴权（Task::canUploadFile / File::canDownload / Knowledge::isEditableBy ...）
 * 都以该用户的真实权限为准 —— 即"谁有权限，谁就能授权 AI"。
 */
class AiApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('pm.ai_api_key') ?: env('AI_API_KEY');

        if (!$expected || $expected === '') {
            return response()->json([
                'error' => 'AI_API_KEY 未配置，请在 .env 中设置 AI_API_KEY 后再调用数据 API。',
            ], 401);
        }

        $provided = $request->header('X-AI-Key') ?: $request->query('api_key');

        if (!hash_equals((string) $expected, (string) $provided)) {
            return response()->json(['error' => '未授权：API Key 无效。'], 401);
        }

        // ---- 解析用户委托令牌（可选，但写操作与文件下载必需）----
        $plain = $request->header('X-AI-Token') ?: $request->query('ai_token');
        $token = null;

        if ($plain) {
            $token = AiDelegationToken::findByPlain((string) $plain);

            if (!$token || !$token->isValid()) {
                return response()->json([
                    'error' => '未授权：委托令牌无效、已吊销或已过期。',
                ], 401);
            }
        } elseif (!config('pm.ai_api.allow_anonymous_read', true)) {
            return response()->json([
                'error' => '未授权：本接口必须携带用户委托令牌（X-AI-Token）。',
            ], 401);
        }

        if ($token) {
            $user = $token->user;
            // 让本次请求以「委托人」身份运行，业务侧 auth()->id() / 模型鉴权自动生效
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('ai.token', $token);
            $request->attributes->set('ai.user', $user);
            $token->touchUsed();
        }

        return $next($request);
    }
}
