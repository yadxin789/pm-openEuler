<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 数据 API 访问认证：校验 X-AI-Key 请求头或 api_key 参数。
 * 密钥来自 .env 的 AI_API_KEY（也建议在后台 AI 设置中查看）。
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

        return $next($request);
    }
}
