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
        $maskedKey = $current->maskedApiKey();
        return view('ai.welcome', compact('current', 'providers', 'maskedKey'));
    }

    /**
     * 「管理员尚未开通」提示页（非管理员 / 未启用时展示）
     */
    public function notice()
    {
        return view('ai.notice');
    }

    /**
     * AI 聊天页（仅供普通业务角色使用）
     * - 管理员：管理员只负责管理 AI（供应商 / Key / 限流），不使用 AI → 跳转 AI 设置页
     * - AI 未开通：跳转「管理员尚未开通」提示页
     */
    public function chat()
    {
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings')
                ->with('warning', '管理员账号用于管理 AI（供应商、API Key、调用限流等），不提供 AI 聊天功能。请使用普通业务账号登录使用 AI。');
        }

        $setting = AiSetting::current();
        if (!$setting->isAvailable()) {
            return redirect()->route('ai.notice');
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

        // 管理员不使用 AI，仅做管理
        if ($this->isAdmin()) {
            return response()->json([
                'reply' => '🚫 管理员账号仅用于管理 AI 设置，不提供 AI 聊天功能，请使用普通业务账号使用 AI。',
            ], 403);
        }

        $setting = AiSetting::current();
        if (!$setting->isAvailable()) {
            return response()->json(['reply' => '⚠️ AI 尚未开通，请联系系统管理员在「AI 设置」中配置并启用。'], 403);
        }

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
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings');
        }
        Session::forget('ai_chat_history');
        return back()->with('success', '对话已清空');
    }

    // ==================== 设置 ====================

    /**
     * AI 设置页（管理员专用：供应商 / API Key / 模型 / 限流 / 启停）
     */
    public function settings()
    {
        $this->authorize('ai-settings');
        $setting = AiSetting::current();
        $providers = AiSetting::providers();
        $providerInfo = $setting->providerInfo();
        $maskedKey = $setting->maskedApiKey();
        return view('ai.settings', compact('setting', 'providers', 'providerInfo', 'maskedKey'));
    }

    /**
     * 保存设置
     */
    public function updateSettings(Request $request)
    {
        $this->authorize('ai-settings');

        // 说明：开通引导页只提交 provider/api_key/enabled，
        //      其余字段留空由下方按「默认值 / 原值」兜底，避免表单校验失败。
        $validated = $request->validate([
            'provider' => 'required|string|in:deepseek,qwen,doubao,hunyuan',
            'api_key' => 'nullable|string|max:500',
            'base_url' => 'nullable|string|max:500',
            'model' => 'nullable|string|max:100',
            'vision_enabled' => 'boolean',
            'vision_model' => 'nullable|string|max:100',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'max_tokens' => 'nullable|integer|min:100|max:8000',
            'rate_limit_minute' => 'nullable|integer|min:1|max:1000',
            'rate_limit_daily' => 'nullable|integer|min:1|max:100000',
            'enabled' => 'boolean',
        ]);

        $setting = AiSetting::current();
        $providers = AiSetting::providers();

        // 开关类字段未勾选时也要能关闭（validate 结果不含未提交的键）
        $validated['enabled'] = $request->boolean('enabled');
        $validated['vision_enabled'] = $request->boolean('vision_enabled');

        // 未填字段回退到原值
        $validated['temperature'] = $validated['temperature'] ?? $setting->temperature ?? 0.7;
        $validated['max_tokens'] = $validated['max_tokens'] ?? $setting->max_tokens ?? 2000;
        $validated['rate_limit_minute'] = $validated['rate_limit_minute'] ?? $setting->rate_limit_minute ?? 30;
        $validated['rate_limit_daily'] = $validated['rate_limit_daily'] ?? $setting->rate_limit_daily ?? 200;

        // 模型：留空或不属于新提供商时，自动取该提供商的第一个模型
        $providerModels = array_keys($providers[$validated['provider']]['models'] ?? []);
        if (empty($validated['model']) || ($providerModels && !in_array($validated['model'], $providerModels, true))) {
            $validated['model'] = $providerModels[0] ?? ($setting->model ?: 'deepseek-chat');
        }

        // 未填 API Key 时保留原值
        if (empty($validated['api_key'])) {
            unset($validated['api_key']);
        }

        $oldDefaultUrl = $providers[$setting->provider]['base_url'] ?? null;
        $newDefaultUrl = $providers[$validated['provider']]['base_url'] ?? null;

        if (empty($validated['base_url'])
            || ($oldDefaultUrl && $setting->base_url === $oldDefaultUrl)) {
            // 留空、或仍是旧提供商默认地址时，自动改为新提供商的默认地址
            $validated['base_url'] = $newDefaultUrl;
        }

        $setting->update($validated);

        // 开通引导页提交后统一落到 AI 设置页，便于继续配置限流/供应商/Key
        $fromWelcome = $request->input('source') === 'welcome';
        $message = $fromWelcome
            ? 'AI 已开通！请在本页确认供应商、API Key 与调用限流设置，建议点击「测试连接」验证可用性。'
            : 'AI 设置已保存';

        return redirect()->route('ai.settings')->with('success', $message);
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
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings')
                ->with('warning', '管理员账号仅用于管理 AI 设置，不提供 AI 文档生成功能。');
        }
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
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings')
                ->with('warning', '管理员账号仅用于管理 AI 设置，不提供 AI 文档生成功能。');
        }

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
