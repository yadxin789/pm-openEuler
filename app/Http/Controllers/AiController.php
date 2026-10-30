<?php

namespace App\Http\Controllers;

use App\Ai\Tools\ListWorkspaceFiles;
use App\Ai\Tools\ReadWorkspaceFile;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiDocTemplate;
use App\Models\AiMessage;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\AiSkill;
use App\Services\AiService;
use App\Services\AiWorkspaceService;
use App\Services\DocumentTextExtractorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\LocalImage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * AI 聊天页（多会话版：支持历史会话、图片/文件附件、技能）
     */
    public function chat(Request $request)
    {
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings')
                ->with('warning', '管理员账号用于管理 AI（供应商、API Key、调用限流等），不提供 AI 聊天功能。请使用普通业务账号登录使用 AI。');
        }

        $setting = AiSetting::current();
        $ai = app(AiService::class);
        if (!$setting->enabled || !$ai->isAvailable()) {
            return redirect()->route('ai.notice');
        }

        // 老版本把对话存在 Session 里，这里一次性迁移为会话记录
        $this->migrateLegacySessionHistory();

        $userId = auth()->id();
        $conversations = AiConversation::where('user_id', $userId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $conversation = null;
        if ($request->filled('c')) {
            $conversation = AiConversation::where('user_id', $userId)->find($request->query('c'));
        }
        if (!$conversation) {
            $conversation = $conversations->first();
        }

        $messages = $conversation ? $conversation->messages()->with('attachments')->get() : collect();
        $skills = AiSkill::where('enabled', true)->orderBy('id')->get();

        // 用户可切换的 AI 模型（模型启用 + 所属供应商已配置 Key）
        $models = AiModel::usable()->get();
        $currentModel = $conversation?->model_id
            ? AiModel::with('provider')->where('id', $conversation->model_id)->first()
            : null;
        $currentModel = ($currentModel && $currentModel->isUsable()) ? $currentModel : AiModel::usableDefault();

        $providerInfo = $setting->providerInfo();
        $model = $setting->model;
        $visionEnabled = $ai->visionEnabled();
        $rateLimit = [
            'minute' => (int) ($setting->rate_limit_minute ?: 30),
            'daily' => (int) ($setting->rate_limit_daily ?: 200),
        ];

        return view('ai.chat', compact(
            'conversations', 'conversation', 'messages', 'skills', 'providerInfo',
            'rateLimit', 'model', 'visionEnabled', 'models', 'currentModel'
        ));
    }

    /**
     * 发送消息（支持会话、技能、图片/文件附件 + PM 数据工具调用）
     */
    public function ask(Request $request, AiService $ai)
    {
        // 管理员不使用 AI，仅做管理
        if ($this->isAdmin()) {
            return response()->json([
                'reply' => '🚫 管理员账号仅用于管理 AI 设置，不提供 AI 聊天功能，请使用普通业务账号使用 AI。',
            ], 403);
        }

        $setting = AiSetting::current();
        if (!$setting->enabled || !$ai->isAvailable()) {
            return response()->json(['reply' => '⚠️ AI 尚未开通，请联系系统管理员在「AI 设置」中配置至少一个供应商与模型。'], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:4000',
            'conversation_id' => 'nullable|integer',
            'model_id' => 'nullable|integer',
            'skill' => 'nullable|string|max:100',
            'images.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,bmp,webp|max:10240',
            'files.*' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,xlsm,ods,ppt,pptx,zip,txt,md,csv,json,xml,log,yaml,yml,ini,sql,html,htm,odt|max:20480',
        ]);

        $message = trim((string) $request->input('message', ''));
        $hasUpload = $request->hasFile('images') || $request->hasFile('files');
        if ($message === '' && !$hasUpload) {
            return response()->json(['reply' => '请输入问题，或上传图片 / 文件后再发送。'], 422);
        }

        // 定位 / 创建会话
        $conversation = $this->resolveConversation($request->input('conversation_id'), $request->input('skill'));

        // 本次使用的模型：请求指定 > 会话已选 > 系统默认可用模型
        $modelId = $request->input('model_id') ?: $conversation->model_id;
        $model = $ai->resolveModel($modelId ? (int) $modelId : null);
        if (!$model) {
            return response()->json([
                'reply' => '⚠️ 当前没有可用的 AI 模型，请管理员在「AI 设置」中配置至少一个供应商 Key 并启用模型。',
            ], 422);
        }
        $ai->useModel($model);

        // 技能 → 工具子集 + 专项指令
        $skill = null;
        if ($request->filled('skill')) {
            $skill = AiSkill::where('slug', $request->input('skill'))->where('enabled', true)->first();
        }
        $tools = $skill ? $ai->toolsForNames($skill->config['tools'] ?? []) : null;
        $extraInstructions = $skill ? ($skill->description ?: null) : null;

        // 注入会话工作区工具：让 AI 能浏览/读取已解压的 ZIP 文件内容
        $workspaceTools = [
            new ListWorkspaceFiles($conversation->id),
            new ReadWorkspaceFile($conversation->id),
        ];
        if (is_array($tools)) {
            $tools = array_merge($tools, $workspaceTools);
        } else {
            // 无技能限定 = 全部 PM 工具 + 工作区工具
            $tools = array_merge($ai->pmTools(), $workspaceTools);
        }

        // 处理附件：入库 + 生成可直接投喂 AI 的内容
        [$attachmentMeta, $aiAttachments, $fileContext, $notes] = $this->handleUploads($request, $conversation, $ai);

        // 组装最终投喂文本
        $promptParts = [];
        if ($fileContext !== '') {
            $promptParts[] = "【附件说明】以下是用户本轮上传的文件的原始文本内容，由系统自动解析后提供给你，"
                . "请勿声称看不到或未收到文件内容；可直接引用其中的数据、结论与原文来回答用户提问。\n\n"
                . $fileContext;
        }
        if ($message !== '') {
            $promptParts[] = $message;
        }
        foreach ($notes as $note) {
            $promptParts[] = $note;
        }
        $prompt = implode("\n\n", $promptParts);

        // 历史上下文（最近 20 条）
        $history = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $m) => ['role' => $m->role, 'content' => (string) $m->content])
            ->values()
            ->all();

        $reply = $ai->chatWithTools($prompt, $history, $tools, $aiAttachments, $extraInstructions);

        $modelMeta = [
            'skill' => $skill ? $skill->slug : null,
            'model_id' => $model->id,
            'model' => $model->model_id,
            'model_name' => $model->name,
            'provider' => $model->provider?->name,
        ];

        // 落库
        $userMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message !== '' ? $message : '（用户仅上传了附件，见下方附件）',
            'meta' => $modelMeta,
        ]);
        $assistantMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'meta' => $modelMeta,
        ]);
        AiAttachment::where('conversation_id', $conversation->id)
            ->whereNull('message_id')
            ->update(['message_id' => $userMessage->id]);

        $conversation->update([
            'title' => $this->titleForConversation($conversation, $message),
            'skill_slug' => $skill ? $skill->slug : $conversation->skill_slug,
            'model_id' => $model->id,
            'message_count' => $conversation->messages()->count(),
            'last_message_at' => now(),
        ]);

        return response()->json([
            'reply' => $reply,
            'conversation_id' => $conversation->id,
            'conversation_title' => $conversation->title,
            'user_message_id' => $userMessage->id,
            'message_id' => $assistantMessage->id,
            'model_id' => $model->id,
            'model_name' => $model->badge(),
            'provider_name' => $model->provider?->name,
            'attachments' => $attachmentMeta,
        ]);
    }

    /**
     * 新建会话（空白）
     */
    public function storeConversation(Request $request)
    {
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings');
        }
        $skill = $request->input('skill');
        $model = AiModel::usable()->where('id', (int) $request->input('model_id'))->first();
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => '新会话',
            'skill_slug' => $skill ?: null,
            'model_id' => $model?->id,
            'last_message_at' => now(),
        ]);
        return redirect()->route('ai.chat', ['c' => $conversation->id]);
    }

    /**
     * 重命名会话
     */
    public function renameConversation(Request $request, AiConversation $conversation)
    {
        $this->assertOwner($conversation);
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'model_id' => 'nullable|integer',
        ]);

        $patch = [];
        if (!empty($validated['title'])) {
            $patch['title'] = $validated['title'];
        }
        // 切换本次会话使用的模型（用户在聊天页随时切换）
        if (!empty($validated['model_id'])) {
            $model = AiModel::usable()->where('id', (int) $validated['model_id'])->first();
            if (!$model) {
                return response()->json(['ok' => false, 'message' => '该模型不可用，请刷新后重试。'], 422);
            }
            $patch['model_id'] = $model->id;
        }
        if ($patch) {
            $conversation->update($patch);
        }

        // AJAX 直接返回 JSON：若返回重定向，浏览器会用 PATCH 方法跟随跳转导致 405
        if ($request->ajax()) {
            return response()->json([
                'ok' => true,
                'title' => $conversation->title,
                'model_id' => $conversation->model_id,
                'model_name' => $conversation->model?->badge(),
            ]);
        }
        return back()->with('success', $patch['title'] ?? false ? '会话名称已更新' : '已切换模型');
    }

    /**
     * 删除会话（含附件物理文件）
     */
    public function destroyConversation(AiConversation $conversation)
    {
        $this->assertOwner($conversation);
        $this->deleteConversationFiles($conversation);
        $conversation->forceDelete();

        // AJAX 必须返回 JSON：若返回 302，浏览器会用 DELETE 方法跟随跳转到 /ai/chat（405）
        if (request()->ajax()) {
            return response()->json(['ok' => true]);
        }
        return redirect()->route('ai.chat')->with('success', '会话已删除');
    }

    /**
     * 清空某个会话的消息（保留会话本身）
     */
    public function clearConversation(AiConversation $conversation)
    {
        $this->assertOwner($conversation);
        $this->deleteConversationFiles($conversation, true);
        $conversation->messages()->delete();
        $conversation->update(['message_count' => 0, 'last_message_at' => now()]);
        return back()->with('success', '当前会话消息已清空');
    }

    /**
     * 清空对话（兼容旧路由：清空 / 当前会话）
     */
    public function clear(Request $request)
    {
        if ($this->isAdmin()) {
            return redirect()->route('ai.settings');
        }
        Session::forget('ai_chat_history');

        $conversation = null;
        if ($request->filled('conversation_id')) {
            $conversation = AiConversation::where('user_id', auth()->id())->find($request->input('conversation_id'));
        }
        if (!$conversation) {
            $conversation = AiConversation::where('user_id', auth()->id())->orderByDesc('last_message_at')->first();
        }
        if ($conversation) {
            return $this->clearConversation($conversation);
        }
        return redirect()->route('ai.chat')->with('success', '暂无可清空的会话');
    }

    /**
     * 导出会话为 Markdown
     */
    public function exportConversation(AiConversation $conversation): StreamedResponse
    {
        $this->assertOwner($conversation);
        $conversation->load(['messages.attachments']);

        $lines = [
            '# ' . $conversation->title,
            '',
            '> 导出时间：' . now()->format('Y-m-d H:i:s'),
            '',
        ];
        foreach ($conversation->messages as $msg) {
            $who = $msg->role === 'user' ? '👤 我' : '🤖 AI 助手';
            $lines[] = '## ' . $who;
            $lines[] = '';
            $lines[] = (string) $msg->content;
            if ($msg->attachments->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '附件：' . $msg->attachments
                    ->map(fn (AiAttachment $a) => $a->original_name)
                    ->implode('、');
            }
            $lines[] = '';
        }

        $filename = 'AI会话_' . Str::of($conversation->title)->replace(['/', '\\'], '_')->limit(40, '') . '.md';

        return response()->streamDownload(function () use ($lines) {
            echo implode("\n", $lines);
        }, $filename, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    /**
     * 预览附件（图片内联展示）
     */
    public function previewAttachment(AiAttachment $attachment)
    {
        $this->assertAttachmentOwner($attachment);
        if (!$attachment->exists()) {
            abort(404, '文件不存在或已被清理');
        }
        return response()->file($attachment->absolutePath(), [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . rawurlencode($attachment->original_name) . '"',
        ]);
    }

    /**
     * 下载附件
     */
    public function downloadAttachment(AiAttachment $attachment)
    {
        $this->assertAttachmentOwner($attachment);
        if (!$attachment->exists()) {
            abort(404, '文件不存在或已被清理');
        }
        return response()->download($attachment->absolutePath(), $attachment->original_name);
    }

    // ==================== 聊天内部辅助方法 ====================

    /**
     * 把旧版本存在 Session 里的对话迁移为会话记录（仅执行一次）
     */
    protected function migrateLegacySessionHistory(): void
    {
        $history = Session::get('ai_chat_history', []);
        if (empty($history)) {
            return;
        }

        $has = AiConversation::where('user_id', auth()->id())->exists();
        if ($has) {
            Session::forget('ai_chat_history');
            return;
        }

        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => '历史会话（旧版迁移）',
            'last_message_at' => now(),
        ]);
        foreach ($history as $h) {
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => $h['role'] ?? 'user',
                'content' => $h['content'] ?? '',
            ]);
        }
        $conversation->update(['message_count' => count($history)]);
        Session::forget('ai_chat_history');
    }

    /**
     * 取得当前会话：有 id 就用（需属于本人），否则新建一个
     */
    protected function resolveConversation($conversationId = null, ?string $skill = null): AiConversation
    {
        if ($conversationId) {
            $found = AiConversation::where('user_id', auth()->id())->find($conversationId);
            if ($found) {
                if ($skill && $found->skill_slug !== $skill) {
                    $found->update(['skill_slug' => $skill]);
                }
                return $found;
            }
        }

        return AiConversation::create([
            'user_id' => auth()->id(),
            'title' => '新会话',
            'skill_slug' => $skill ?: null,
            'last_message_at' => now(),
        ]);
    }

    /**
     * 处理上传的图片 / 文件
     *
     * @return array{0:array,1:array,2:string,3:array} [附件展示元数据, AI SDK 附件对象, 文本内容, 提示语]
     */
    protected function handleUploads(Request $request, AiConversation $conversation, AiService $ai): array
    {
        $meta = [];
        $sdkAttachments = [];
        $contextParts = [];
        $notes = [];
        $imageNames = [];
        $unsupportedNames = [];

        $userId = auth()->id();
        $dir = 'u' . $userId . '/' . date('Ymd');

        // 图片
        foreach ($request->file('images', []) as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            $path = $file->storeAs($dir, Str::random(16) . '.' . $file->getClientOriginalExtension(), 'ai_chat');
            $attachment = AiAttachment::create([
                'conversation_id' => $conversation->id,
                'message_id' => null,
                'user_id' => $userId,
                'type' => 'image',
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
            $meta[] = [
                'id' => $attachment->id,
                'type' => 'image',
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'readable_size' => $attachment->readableSize(),
                'preview_url' => route('ai.attachments.preview', $attachment->id),
            ];

            if ($ai->visionEnabled()) {
                $sdkAttachments[] = new LocalImage($attachment->absolutePath());
            } else {
                $imageNames[] = $attachment->original_name;
            }
        }

        // 文件
        foreach ($request->file('files', []) as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            $ext = strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs($dir, Str::random(16) . '.' . $ext, 'ai_chat');
            $realPath = $file->getRealPath();
            $text = $this->extractText($realPath, $ext);

            $attachment = AiAttachment::create([
                'conversation_id' => $conversation->id,
                'message_id' => null,
                'user_id' => $userId,
                'type' => 'file',
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'text_extracted' => (bool) $text,
                'extracted_text' => $text ? mb_substr($text, 0, 20000) : null,
            ]);
            $meta[] = [
                'id' => $attachment->id,
                'type' => 'file',
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'readable_size' => $attachment->readableSize(),
                'download_url' => route('ai.attachments.download', $attachment->id),
            ];

            // ZIP / 压缩包：自动解压到会话工作区，AI 可通过工具浏览
            if ($ext === 'zip') {
                $workspace = app(AiWorkspaceService::class);
                [$ok, $result] = $workspace->extractZip($conversation->id, $realPath, $file->getClientOriginalName());
                if ($ok) {
                    $contextParts[] = "=== 压缩包：{$attachment->original_name} ===";
                    $contextParts[] = "已自动解压到会话工作区（{$result}）。"
                        . "你可以使用 ListWorkspaceFiles 工具查看解压后的文件列表，"
                        . "使用 ReadWorkspaceFile 工具读取其中某个文件的内容。"
                        . "请告诉用户压缩包已解压，你可以浏览其中的文件。";
                } else {
                    $unsupportedNames[] = $attachment->original_name . '（解压失败：' . $result . '）';
                }
            } elseif ($text !== '') {
                $contextParts[] = "=== 文件：{$attachment->original_name} ===";
                $contextParts[] = mb_substr($text, 0, 20000);
            } else {
                $unsupportedNames[] = $attachment->original_name;
            }
        }

        if ($imageNames) {
            $notes[] = '本次还上传了图片（' . implode('、', $imageNames)
                . '）。当前 AI 未开启视觉识别能力，无法读取图片内容，请结合文字描述回答；如需「看图」，请让管理员在 AI 设置中开启视觉模型。';
        }
        if ($unsupportedNames) {
            $notes[] = '以下文件暂不支持自动解析内容（仅作记录）：' . implode('、', $unsupportedNames)
                . '。如需分析其内容，请粘贴文本或说明要点。';
        }

        return [$meta, $sdkAttachments, implode("\n", $contextParts), $notes];
    }

    /**
     * 抽取文件文本内容（委托给 DocumentTextExtractorService）
     *
     * 支持：txt/md/csv/json/xml/log/yaml/sql/html 等纯文本，
     *      pdf（smalot/pdfparser 或 pdftotext），
     *      docx（OOXML 解压），doc（antiword），
     *      xlsx/xls/xlsm/ods（phpspreadsheet），
     *      pptx（OOXML 解压），odt。
     *
     * 无法解析的格式返回空串，由上层标记为「暂不支持自动解析」。
     */
    protected function extractText(string $realPath, string $extension): string
    {
        return app(DocumentTextExtractorService::class)->extract($realPath, $extension);
    }

    /**
     * 会话标题：首个用户问题的前 24 个字符
     */
    protected function titleForConversation(AiConversation $conversation, string $message): string
    {
        if ($conversation->title && $conversation->title !== '新会话') {
            return $conversation->title;
        }
        $first = $conversation->messages()->where('role', 'user')->value('content') ?: $message;
        $first = trim((string) $first);
        if ($first === '') {
            return $conversation->title ?: '新会话';
        }
        return mb_strimwidth(str_replace(["\r", "\n"], ' ', $first), 0, 24, '…');
    }

    /**
     * 清理会话附件（物理文件 + 记录）
     *
     * @param bool $keepConversation 是否保留会话（仅清空消息时用）
     */
    protected function deleteConversationFiles(AiConversation $conversation, bool $keepConversation = false): void
    {
        $attachments = AiAttachment::where('conversation_id', $conversation->id)->get();
        foreach ($attachments as $attachment) {
            Storage::disk('ai_chat')->delete($attachment->path);
        }
        AiAttachment::where('conversation_id', $conversation->id)->delete();

        // 同时清理会话工作区（ZIP 解压的临时文件）
        app(AiWorkspaceService::class)->cleanup($conversation->id);
    }

    protected function assertOwner(AiConversation $conversation): void
    {
        if ($conversation->user_id !== auth()->id()) {
            abort(403, '无权操作该会话。');
        }
    }

    protected function assertAttachmentOwner(AiAttachment $attachment): void
    {
        if ($attachment->user_id !== auth()->id()) {
            abort(403, '无权访问该附件。');
        }
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

        // 已配置的供应商（按表里的记录），未建过的预设供应商自动补齐
        $rows = AiProvider::orderBy('id')->get()->keyBy('key');
        foreach ($providers as $key => $p) {
            if (!$rows->has($key)) {
                AiProvider::create([
                    'key' => $key,
                    'name' => $p['name'],
                    'base_url' => $p['base_url'],
                    'enabled' => false,
                ]);
            }
        }
        $rows = AiProvider::with('models')->orderBy('id')->get();

        $models = AiModel::with('provider')->orderBy('provider_id')->orderBy('sort_order')->get();
        $defaultModel = AiModel::usableDefault();
        $providerInfo = $setting->providerInfo();
        $maskedKey = $setting->maskedApiKey();

        return view('ai.settings', compact(
            'setting', 'providers', 'providerInfo', 'maskedKey', 'rows', 'models', 'defaultModel'
        ));
    }

    // ==================== 多供应商 / 多模型管理 ====================

    /**
     * 保存某个供应商的配置（API Key / Base URL / 启停）
     */
    public function saveProvider(Request $request, string $provider)
    {
        $this->authorize('ai-settings');
        $catalog = AiSetting::providers();
        if (!isset($catalog[$provider])) {
            return back()->with('error', '不支持的供应商。');
        }

        $validated = $request->validate([
            'api_key' => 'nullable|string|max:500',
            'base_url' => 'nullable|string|max:500',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $row = AiProvider::firstOrNew(['key' => $provider]);
        $row->name = $catalog[$provider]['name'];
        $row->base_url = trim((string) ($validated['base_url'] ?? '')) ?: $catalog[$provider]['base_url'];

        // Key 留空表示保持原值；输入即为新值
        if (!empty($validated['api_key'])) {
            $row->api_key = trim($validated['api_key']);
        }
        $row->enabled = $request->boolean('enabled');
        $row->save();

        if ($request->boolean('is_default')) {
            $row->markAsDefault();
        }

        // 同步 ai_settings，保证其它依赖单供应商配置的地方不被破坏
        $row->syncToLegacySetting();

        return redirect()->route('ai.settings')->with('success', $row->name . ' 配置已保存。');
    }

    /**
     * 启用 / 停用供应商
     */
    public function toggleProvider(string $provider)
    {
        $this->authorize('ai-settings');
        $row = AiProvider::where('key', $provider)->firstOrFail();
        $row->update(['enabled' => !$row->enabled]);
        $row->syncToLegacySetting();
        $msg = $row->enabled ? '已启用' : '已停用';
        return back()->with('success', $row->name . " {$msg}。");
    }

    /**
     * 设为默认供应商
     */
    public function setDefaultProvider(string $provider)
    {
        $this->authorize('ai-settings');
        $row = AiProvider::where('key', $provider)->firstOrFail();
        $row->markAsDefault();
        $row->syncToLegacySetting();
        return back()->with('success', '默认供应商已设为：' . $row->name);
    }

    /**
     * 清空某个供应商的密钥（保留供应商与模型配置）
     */
    public function removeProvider(string $provider)
    {
        $this->authorize('ai-settings');
        $row = AiProvider::where('key', $provider)->firstOrFail();
        $row->update(['api_key' => null, 'enabled' => false]);
        return back()->with('success', $row->name . ' 的密钥已清除并停用。');
    }

    /**
     * 测试某个供应商（可指定模型）能否连通
     */
    public function testProvider(Request $request, string $provider, AiService $ai)
    {
        $this->authorize('ai-settings');
        $row = AiProvider::where('key', $provider)->firstOrFail();
        $modelId = (int) $request->input('model_id');
        $model = $modelId ? AiModel::where('provider_id', $row->id)->where('id', $modelId)->first() : null;
        $result = $ai->testConnectionFor($row, $model);
        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * 把预设模型列表补齐到该供应商（新增厂家新模型时可用）
     */
    public function syncProviderModels(string $provider)
    {
        $this->authorize('ai-settings');
        $row = AiProvider::where('key', $provider)->firstOrFail();
        $existing = $row->models()->pluck('model_id')->all();
        $order = $row->models()->max('sort_order') ?? 0;
        $added = 0;
        foreach ($row->presetModels() as $modelId => $label) {
            if (in_array($modelId, $existing, true)) {
                continue;
            }
            AiModel::create([
                'provider_id' => $row->id,
                'model_id' => $modelId,
                'name' => $label,
                'enabled' => true,
                'sort_order' => ++$order,
            ]);
            $added++;
        }
        return back()->with('success', $added ? "已补齐 {$added} 个模型。" : '预设模型已全部存在，无需补齐。');
    }

    /**
     * 新增自定义模型（支持厂家未收录的新模型）
     */
    public function storeModel(Request $request)
    {
        $this->authorize('ai-settings');
        $validated = $request->validate([
            'provider_id' => 'required|integer|exists:ai_providers,id',
            'model_id' => 'required|string|max:120',
            'name' => 'nullable|string|max:150',
            'is_vision' => 'boolean',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $provider = AiProvider::findOrFail($validated['provider_id']);
        $exists = AiModel::where('provider_id', $provider->id)->where('model_id', $validated['model_id'])->first();
        if ($exists) {
            return back()->with('error', '该供应商下已存在同名模型标识。');
        }

        $model = AiModel::create([
            'provider_id' => $provider->id,
            'model_id' => trim($validated['model_id']),
            'name' => trim($validated['name'] ?? '') ?: $validated['model_id'],
            'is_vision' => $request->boolean('is_vision'),
            'enabled' => $request->boolean('enabled', true),
            'sort_order' => (int) ($provider->models()->max('sort_order') ?? 0) + 1,
        ]);

        if ($request->boolean('is_default')) {
            $model->markAsDefault();
        }
        return back()->with('success', '模型已新增：' . $model->name);
    }

    /**
     * 修改模型（名称 / 是否支持看图 / 是否开放给用户）
     */
    public function updateModel(Request $request, AiModel $model)
    {
        $this->authorize('ai-settings');
        $validated = $request->validate([
            'name' => 'nullable|string|max:150',
            'model_id' => 'nullable|string|max:120',
            'enabled' => 'boolean',
            'is_vision' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $data = array_filter([
            'name' => $validated['name'] ?? null,
            'model_id' => $validated['model_id'] ?? null,
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data['is_vision'] = $request->boolean('is_vision');
        $model->update($data);

        if ($request->boolean('is_default')) {
            $model->markAsDefault();
        }
        return back()->with('success', '模型已更新：' . $model->name);
    }

    /**
     * 设为默认模型
     */
    public function setDefaultModel(AiModel $model)
    {
        $this->authorize('ai-settings');
        $model->markAsDefault();
        $model->provider?->syncToLegacySetting();
        return back()->with('success', '默认模型已设为：' . $model->fullLabel());
    }

    /**
     * 删除模型
     */
    public function destroyModel(AiModel $model)
    {
        $this->authorize('ai-settings');
        $name = $model->name;
        $model->delete();
        return back()->with('success', '模型已删除：' . $name);
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

        // 同步到多供应商表：本次提交的 provider 即当前编辑的供应商
        $providerRow = AiProvider::where('key', $validated['provider'])->first();
        if ($providerRow) {
            $patch = ['base_url' => $setting->base_url];
            if (!empty($validated['api_key'])) {
                $patch['api_key'] = $validated['api_key'];
            }
            if ($providerRow->api_key || !empty($patch['api_key'])) {
                $patch['enabled'] = $request->boolean('enabled');
            }
            $providerRow->update($patch);
            if ($providerRow->enabled && !AiProvider::where('is_default', true)->exists()) {
                $providerRow->markAsDefault();
            }
            // 当前模型同步标记为默认模型
            if ($setting->model) {
                $m = AiModel::where('provider_id', $providerRow->id)->where('model_id', $setting->model)->first();
                if ($m) {
                    $m->markAsDefault();
                }
            }
        }

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
        $models = AiModel::usable()->get();
        $defaultModel = AiModel::usableDefault();
        return view('ai.docs', compact('templates', 'generated', 'models', 'defaultModel'));
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
            'model_id' => 'nullable|integer',
        ]);

        $template = AiDocTemplate::findOrFail($request->template_id);

        if (!$ai->isAvailable()) {
            return back()->with('error', 'AI 尚未配置，请先在「AI 智能助手」中配置 API Key 并启用。');
        }

        // 本次生成使用的模型
        if ($model = $ai->resolveModel((int) $request->input('model_id') ?: null)) {
            $ai->useModel($model);
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
