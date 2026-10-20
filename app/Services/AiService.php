<?php

namespace App\Services;

use App\Ai\Tools\GetDocTemplate;
use App\Ai\Tools\QueryDepartments;
use App\Ai\Tools\QueryFiles;
use App\Ai\Tools\QueryKnowledge;
use App\Ai\Tools\QueryPmOverview;
use App\Ai\Tools\QueryProjectDetail;
use App\Ai\Tools\QueryProjects;
use App\Ai\Tools\QueryTasks;
use App\Ai\Tools\QueryTodos;
use App\Ai\Tools\QueryUsers;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Models\AiSetting;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Illuminate\Support\Facades\Log;

/**
 * AI 服务：桥接「DB 中的 AI 配置」与「Laravel AI SDK」。
 * 参考 ERP 的 AiService 设计，但底层使用官方 laravel/ai 包。
 */
class AiService
{
    protected AiSetting $config;

    public function __construct(?AiSetting $config = null)
    {
        $this->config = $config ?: AiSetting::current();
    }

    public function config(): AiSetting
    {
        return $this->config;
    }

    public function isAvailable(): bool
    {
        return $this->config->isAvailable();
    }

    /**
     * 将 DB 中的提供商配置动态写入 laravel/ai，返回对应的 Lab 枚举。
     */
    protected function applyProviderConfig(): Lab
    {
        $providerInfo = $this->config->providerInfo();
        $lab = Lab::from($providerInfo['lab'] ?? 'deepseek');

        // key 注入
        config(["ai.providers.{$lab->value}.key" => $this->config->api_key]);

        // base_url 注入（openai-compatible 与 deepseek 均支持 url）
        if ($this->config->base_url) {
            config(["ai.providers.{$lab->value}.url" => rtrim($this->config->base_url, '/')]);
        }

        return $lab;
    }

    /**
     * PM 智能助手系统提示词
     */
    public function pmInstructions(): string
    {
        return "你是 PM 产品研发管理系统的智能助手。你可以调用工具查询项目、任务、用户、部门、知识库、文件、待办等真实数据。"
            . "回答用户问题时请用简洁的中文。如果问题需要数据，先调用对应工具，再基于工具返回的结果作答，不要编造数据。"
            . "所有数字、项目名、任务名必须来自工具返回结果。";
    }

    /**
     * 文档生成 Skill 系统提示词
     */
    public function docInstructions(): string
    {
        return "你是 PM 系统的文档生成专家。用户会用自然语言描述文档所需的数据、参数和内容，你需要：\n"
            . "1) 首先调用 GetDocTemplate 工具，传入 template_id 读取「模板文件 / 范例文件 / 支援文件 / 生成指引」。\n"
            . "2) 如果模板或指引要求引用制度、流程、规范，调用 SearchKnowledgeBase 检索知识库原文以保证口径正确。\n"
            . "3) 如文档需要项目/任务等真实数据，可调用 QueryProjects、QueryTasks、QueryProjectDetail、QueryKnowledge 等工具获取。\n"
            . "4) 严格按照模板的结构与占位符，把用户自然语言提供的数据/参数填入模板，生成完整、可直接交付的文档（用 Markdown 输出）。\n"
            . "5) 模板中未提供数据的占位符，若用户未提供，应保留占位并标注【待补充】而不是编造。";
    }

    /**
     * PM 数据查询工具集（任务3/4 的 skills）
     */
    public function pmTools(): array
    {
        return [
            new QueryPmOverview,
            new QueryProjects,
            new QueryProjectDetail,
            new QueryTasks,
            new QueryUsers,
            new QueryDepartments,
            new QueryKnowledge,
            new QueryFiles,
            new QueryTodos,
        ];
    }

    /**
     * 文档生成工具集（任务5 的 skills）
     */
    public function docTools(): array
    {
        return [
            new GetDocTemplate,
            new SearchKnowledgeBase,
            new QueryProjects,
            new QueryProjectDetail,
            new QueryTasks,
            new QueryKnowledge,
            new QueryUsers,
        ];
    }

    /**
     * 将 history 数组转成 Message 对象列表
     */
    protected function toMessages(array $history): array
    {
        $messages = [];
        foreach ($history as $h) {
            $role = $h['role'] ?? 'user';
            $content = $h['content'] ?? '';
            $messages[] = new Message($role, $content);
        }
        return $messages;
    }

    /**
     * 纯文本对话（无工具）
     */
    public function chat(string $userMessage, ?string $systemPrompt = null, array $history = []): ?string
    {
        if (!$this->isAvailable()) {
            Log::warning('AI 未配置或未启用', ['provider' => $this->config->provider]);
            return null;
        }

        try {
            $lab = $this->applyProviderConfig();
            $agent = new AnonymousAgent($systemPrompt ?: '你是一个智能助手。', $this->toMessages($history), []);
            $response = $agent->prompt($userMessage, provider: $lab, model: $this->config->model ?: null);
            return (string) $response;
        } catch (\Throwable $e) {
            Log::error('AI chat 异常: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * 工具注册表：技能/调用方可按名称挑选子集
     *
     * @return array<string, mixed>
     */
    public function toolMap(): array
    {
        return [
            'QueryPmOverview' => new QueryPmOverview,
            'QueryProjects' => new QueryProjects,
            'QueryProjectDetail' => new QueryProjectDetail,
            'QueryTasks' => new QueryTasks,
            'QueryUsers' => new QueryUsers,
            'QueryDepartments' => new QueryDepartments,
            'QueryKnowledge' => new QueryKnowledge,
            'QueryFiles' => new QueryFiles,
            'QueryTodos' => new QueryTodos,
        ];
    }

    /**
     * 按工具名取工具实例集合，取不到时回退到全部 PM 工具
     */
    public function toolsForNames(array $names, bool $fallbackToAll = true): array
    {
        $map = $this->toolMap();
        $tools = [];
        foreach ($names as $name) {
            if (isset($map[$name])) {
                $tools[] = $map[$name];
            }
        }
        if (empty($tools) && $fallbackToAll) {
            return $this->pmTools();
        }
        return $tools;
    }

    /**
     * AI 是否具备「看图」能力（需在 AI 设置中开启视觉并指定视觉模型）
     */
    public function visionEnabled(): bool
    {
        return (bool) $this->config->vision_enabled && !empty($this->config->vision_model);
    }

    /**
     * 带工具调用的对话（Function Calling）
     *
     * @param array $history      历史消息数组：[['role'=>'user','content'=>'...'], ...]
     * @param array|null $tools   工具实例列表，null 表示使用全部 PM 工具
     * @param array $attachments  Laravel AI SDK 附件对象（LocalImage / LocalDocument 等）
     * @param string|null $extraInstructions 附加系统指令（如技能要求）
     */
    public function chatWithTools(
        string $userMessage,
        array $history = [],
        ?array $tools = null,
        array $attachments = [],
        ?string $extraInstructions = null
    ): string {
        if (!$this->isAvailable()) {
            return '⚠️ AI 助手尚未配置。请联系管理员在「AI 设置」中配置 API Key 并启用。';
        }

        try {
            $lab = $this->applyProviderConfig();
            $tools = $tools ?: $this->pmTools();

            $instructions = $this->pmInstructions();
            if ($extraInstructions) {
                $instructions .= "\n\n【本次任务的专项要求】\n" . $extraInstructions;
            }

            // 有图片附件且开启了视觉能力时，切换到视觉模型
            $model = $this->config->model ?: null;
            if (!empty($attachments) && $this->visionEnabled()) {
                $model = $this->config->vision_model;
            }

            $agent = new AnonymousAgent($instructions, $this->toMessages($history), $tools);
            $response = $agent->prompt($userMessage, $attachments, provider: $lab, model: $model, timeout: 120);
            return (string) $response;
        } catch (\Throwable $e) {
            Log::error('AI chatWithTools 异常: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return 'AI 服务异常：' . $e->getMessage();
        }
    }

    /**
     * 自然语言生成文档（任务5 核心）
     *
     * @param int $templateId 文档模板ID
     * @param string $userDescription 用户自然语言描述（数据/参数/内容要求）
     */
    public function generateDocument(int $templateId, string $userDescription): string
    {
        if (!$this->isAvailable()) {
            return '⚠️ AI 尚未配置。请先在 AI 智能助手中配置 API Key 并启用。';
        }

        try {
            $lab = $this->applyProviderConfig();
            $agent = new AnonymousAgent($this->docInstructions(), [], $this->docTools());

            $prompt = "请使用模板ID {$templateId} 生成文档。\n"
                . "用户自然语言描述如下：\n{$userDescription}\n\n"
                . "请先调用 GetDocTemplate 获取模板，再按指引生成完整文档（Markdown）。";

            $response = $agent->prompt($prompt, provider: $lab, model: $this->config->model ?: null, timeout: 180);
            return (string) $response;
        } catch (\Throwable $e) {
            Log::error('AI generateDocument 异常: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return '文档生成失败：' . $e->getMessage();
        }
    }

    /**
     * 测试连接
     */
    public function testConnection(): array
    {
        if (!$this->config->api_key || str_starts_with((string) $this->config->api_key, 'sk-xxxx')) {
            return ['ok' => false, 'message' => '请先填写 API Key'];
        }

        $result = $this->chat('你好，请只回复四个字：连接成功。');
        if ($result && str_contains($result, '连接成功')) {
            return ['ok' => true, 'message' => '连接成功！AI 回复：' . mb_substr($result, 0, 60)];
        }
        return ['ok' => false, 'message' => '连接失败，请检查 API Key、Base URL 和网络。' . ($result ? '（AI回复：' . mb_substr($result, 0, 60) . '）' : '')];
    }

    public function providerInfo(): array
    {
        return $this->config->providerInfo();
    }
}
