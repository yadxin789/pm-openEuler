<?php

namespace App\Http\Controllers;

use App\Models\AiDocTemplate;
use App\Models\AiSetting;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class AiController extends Controller
{
    // ==================== 开通引导 / 聊天 ====================

    /**
     * AI 开通引导页（3 步：选提供商 → 获取 Key → 粘贴并测试）
     * 仅管理员可访问配置；非管理员跳转「尚未开通」提示页。
     */
    public function welcome()
    {
        if (!$this->isAdmin()) {
            return redirect()->route('ai.notice');
        }
        $current = AiSetting::current();
        $providers = AiSetting::providers();
        return view('ai.welcome', compact('current', 'providers'));
    }

    /**
     * 「管理员尚未开通」提示页（非管理员 / 未启用时展示）
     */
    public function notice()
    {
        return view('ai.notice');
    }

    /**
     * AI 聊天页
     * - 未启用：管理员跳转开通引导，非管理员跳转「尚未开通」提示。
     * - 已启用：所有用户均可使用。
     */
    public function chat()
    {
        $setting = AiSetting::current();
        if (!$setting->isAvailable()) {
            return $this->isAdmin()
                ? redirect()->route('ai.welcome')
                : redirect()->route('ai.notice');
        }
        $history = Session::get('ai_chat_history', []);
        $providerInfo = $setting->providerInfo();
        $rateLimit = [
            'minute' => (int) ($setting->rate_limit_minute ?: 30),
            'daily' => (int) ($setting->rate_limit_daily ?: 200),
        ];
        return view('ai.chat', compact('history', 'providerInfo', 'rateLimit'));
    }

    /**
     * 发送消息（带 PM 数据工具调用）
     */
    public function ask(Request $request, AiService $ai)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $history = Session::get('ai_chat_history', []);
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $reply = $ai->chatWithTools($request->message, $history);

        $history[] = ['role' => 'user', 'content' => $request->message];
        $history[] = ['role' => 'assistant', 'content' => $reply ?: '抱歉，暂无回复。'];
        Session::put('ai_chat_history', $history);

        return response()->json(['reply' => $reply ?: '抱歉，暂无回复。']);
    }

    /**
     * 清空对话
     */
    public function clear()
    {
        Session::forget('ai_chat_history');
        return back()->with('success', '对话已清空');
    }

    // ==================== 设置 ====================

    /**
     * AI 设置页
     */
    public function settings()
    {
        $this->authorize('ai-settings');
        $setting = AiSetting::current();
        $providers = AiSetting::providers();
        $providerInfo = $setting->providerInfo();
        return view('ai.settings', compact('setting', 'providers', 'providerInfo'));
    }

    /**
     * 保存设置
     */
    public function updateSettings(Request $request)
    {
        $this->authorize('ai-settings');

        $validated = $request->validate([
            'provider' => 'required|string|in:deepseek,qwen,doubao,hunyuan',
            'api_key' => 'nullable|string|max:500',
            'base_url' => 'nullable|string|max:500',
            'model' => 'required|string|max:100',
            'vision_enabled' => 'boolean',
            'vision_model' => 'nullable|string|max:100',
            'temperature' => 'required|numeric|min:0|max:2',
            'max_tokens' => 'required|integer|min:100|max:8000',
            'rate_limit_minute' => 'required|integer|min:1|max:1000',
            'rate_limit_daily' => 'required|integer|min:1|max:100000',
            'enabled' => 'boolean',
        ]);

        $setting = AiSetting::current();

        // 未填 API Key 时保留原值
        if (empty($validated['api_key'])) {
            unset($validated['api_key']);
        }
        if (empty($validated['base_url'])) {
            // 未填 base_url 时回退到该提供商的默认地址
            $validated['base_url'] = AiSetting::providers()[$validated['provider']]['base_url'] ?? null;
        }

        $setting->update($validated);

        return back()->with('success', 'AI 设置已保存');
    }

    /**
     * 测试连接
     */
    public function test(AiService $ai)
    {
        $this->authorize('ai-settings');
        $result = $ai->testConnection();
        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    // ==================== 自然语言生成文档（任务5）====================

    /**
     * 文档生成页（列出模板）
     */
    public function docs()
    {
        $templates = AiDocTemplate::where('enabled', true)->orderBy('category')->orderBy('id')->get();
        $generated = Session::get('ai_doc_result', null);
        Session::forget('ai_doc_result');
        return view('ai.docs', compact('templates', 'generated'));
    }

    /**
     * 生成文档
     */
    public function generateDoc(Request $request, AiService $ai)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:ai_doc_templates,id',
            'description' => 'required|string|max:4000',
        ]);

        $template = AiDocTemplate::findOrFail($request->template_id);

        if (!$ai->isAvailable()) {
            return back()->with('error', 'AI 尚未配置，请先在「AI 智能助手」中配置 API Key 并启用。');
        }

        // 服务端在途锁：防止同一用户重复/并发提交，浪费共享额度
        $lockKey = 'ai_doc_generate:' . (auth()->id() ?: 'guest');
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 180);
        if (!$lock->get()) {
            return back()->with('error', '您有文档正在生成中，请稍候再试。');
        }

        try {
            $result = $ai->generateDocument((int) $request->template_id, $request->description);
        } finally {
            $lock->release();
        }

        Session::put('ai_doc_result', [
            'template_id' => $template->id,
            'template_name' => $template->name,
            'description' => $request->description,
            'content' => $result,
            'at' => now()->toDateTimeString(),
        ]);

        return back()->with('success', '文档已生成，请在下方面板查看并复制。');
    }

    // ==================== 授权 ====================

    protected function authorize(string $ability): void
    {
        // 设置页仅管理员可访问
        if (auth()->check() && auth()->user()->isAdmin()) {
            return;
        }
        abort(403, '仅管理员可进行 AI 设置。');
    }

    /**
     * 当前登录用户是否为管理员
     */
    protected function isAdmin(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }
}
