<?php

namespace App\Services;

use App\Ai\Tools\GetDocTemplate;
use App\Ai\Tools\ListMyProposals;
use App\Ai\Tools\ProposeDepartmentFileChange;
use App\Ai\Tools\ProposeKnowledgeChange;
use App\Ai\Tools\ProposeKnowledgeDocChange;
use App\Ai\Tools\ProposeTaskAudit;
use App\Ai\Tools\ProposeTaskChange;
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
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;

/**
 * AI 服务：桥接「DB 中的 AI 配置」与「Laravel AI SDK」。
 * 参考 ERP 的 AiService 设计，但底层使用官方 laravel/ai 包。
 */
class AiService
{
    protected AiSetting $config;

    /**
     * 本次调用使用的模型（多模型支持）。null 表示沿用 ai_settings 的单模型配置。
     */
    protected ?AiModel $model = null;

    public function __construct(?AiSetting $config = null, ?AiModel $model = null)
    {
        $this->config = $config ?: AiSetting::current();
        $this->model = $model;
    }

    /**
     * 指定本次调用使用的模型（链式调用）
     */
    public function useModel(?AiModel $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function model(): ?AiModel
    {
        return $this->model;
    }

    public function config(): AiSetting
    {
        return $this->config;
    }

    public function isAvailable(): bool
    {
        // 多模式下：只要存在任意可用模型即视为可用
        if ($this->resolveModel() !== null) {
            return true;
        }
        return $this->config->isAvailable();
    }

    /**
     * 用户可切换的可用模型列表（模型启用 + 供应商启用且已配 Key）
     */
    public function availableModels()
    {
        return AiModel::usable()->get();
    }

    /**
     * 解析要使用的模型：优先指定 id，否则取默认可用模型
     */
    public function resolveModel(?int $modelId = null): ?AiModel
    {
        if ($modelId) {
            $found = AiModel::with('provider')->where('id', $modelId)->first();
            if ($found && $found->isUsable()) {
                return $found;
            }
        }
        return AiModel::usableDefault();
    }

    /**
     * 当前调用使用的模型标识（如 deepseek-chat）
     */
    protected function modelKey(): ?string
    {
        return $this->model?->model_id ?: ($this->config->model ?: null);
    }

    /**
     * 有图片附件时的看图模型：优先同供应商支持视觉的模型
     */
    protected function visionModelKeyForImage(): ?string
    {
        if ($this->model) {
            if ($this->model->is_vision) {
                return $this->model->model_id;
            }
            $sibling = $this->model->provider?->enabledModels()
                ->where('is_vision', true)
                ->first();
            return $sibling?->model_id;
        }
        // 兼容旧版：ai_settings 中单独配置的视觉模型
        return ((bool) $this->config->vision_enabled && $this->config->vision_model)
            ? $this->config->vision_model
            : null;
    }

    /**
     * 是否支持看图
     */
    public function visionEnabled(): bool
    {
        return $this->visionModelKeyForImage() !== null;
    }

    /**
     * 取「供应商 + 密钥」组合：多模型时按模型所属供应商，否则退回旧版单供应商配置
     *
     * @return array{0: AiProvider|null, 1: string, 2: ?string, 3: ?string} [供应商, lab值, key, baseUrl]
     */
    protected function credentials(): array
    {
        $provider = $this->model?->provider;
        if (!$provider) {
            $provider = AiProvider::findByKey((string) $this->config->provider);
        }

        if ($provider) {
            return [
                $provider,
                $provider->lab(),
                $provider->api_key ?: $this->config->api_key,
                $provider->base_url ?: $this->config->base_url,
            ];
        }

        $info = $this->config->providerInfo();
        return [null, $info['lab'] ?? 'deepseek', $this->config->api_key, $this->config->base_url];
    }

    /**
     * 将 DB 中的提供商配置动态写入 laravel/ai，返回对应的 Lab 枚举。
     */
    protected function applyProviderConfig(): Lab
    {
        [, $labValue, $key, $baseUrl] = $this->credentials();
        $lab = Lab::from($labValue ?: 'deepseek');

        // key 注入
        config(["ai.providers.{$lab->value}.key" => $key]);

        // base_url 注入（openai-compatible 与 deepseek 均支持 url）
        if ($baseUrl) {
            config(["ai.providers.{$lab->value}.url" => rtrim($baseUrl, '/')]);
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
            . "所有数字、项目名、任务名必须来自工具返回结果。\n\n"
            . "【变更类操作的硬性规则】\n"
            . "1) 你有权调用 Propose* 系列工具提交「变更提案」，但你永远不能直接修改任何业务数据。\n"
            . "2) 只有在用户本轮对话中明确提出了修改/新建/删除/审核/延期的意图时，才可以调用 Propose* 工具；"
            . "用户只是提问、分析、汇报时，绝对不要主动调用。\n"
            . "3) 调用前如信息不全（如缺任务 ID、缺延期天数、缺标题），先向用户询问，不要猜测填充。\n"
            . "4) Propose* 工具返回的是「待确认提案」，此时数据尚未变更。你必须在回复中明确告诉用户："
            . "变更尚未生效，需本人到「AI 授权中心」确认，并给出工具返回的确认地址。\n"
            . "5) 用户问及变更进展时，用 ListMyProposals 查询状态，不要凭记忆作答。\n"
            . "6) 若工具返回「没有权限」，如实转达并说明原因，不要尝试绕过。\n\n"
            . "【会话工作区与上传文件】\n"
            . "1) 用户上传的 PDF/DOCX/XLSX/PPTX 等文件内容已由系统自动提取并附在消息中，你可以直接引用。\n"
            . "2) 如果用户上传了 ZIP 压缩包（如设计资料库、项目文件包），系统已自动解压到工作区。"
            . "你可以用 ListWorkspaceFiles 查看里面有哪些文件，用 ReadWorkspaceFile 读取具体文件内容来辅助分析和设计。\n"
            . "3) 不要声称看不到用户上传的文件——文件内容已在上下文中提供，或已解压到工作区供你浏览。\n\n"
            . "【系统内无资料时的处理】\n"
            . "当你调用工具查询后，PM 系统中确实没有用户所需的资料时，不要直接说\"没有相关信息\"就结束。"
            . "你应当主动提示用户：\"当前系统中没有查询到相关资料。是否需要我在外网查询相关资料？\""
            . "等待用户确认后，再根据用户的需求进行外网搜索或提供外部参考建议。"
            . "这样可以引导用户获得更完整的信息，而不是止步于系统内部数据。";
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
            // 变更类：只生成待确认提案，需用户本人在 AI 授权中心确认后才生效
            new ProposeTaskChange,
            new ProposeTaskAudit,
            new ProposeKnowledgeChange,
            new ProposeKnowledgeDocChange,
            new ProposeDepartmentFileChange,
            new ListMyProposals,
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
            $response = $agent->prompt($userMessage, provider: $lab, model: $this->modelKey());
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
            'ProposeTaskChange' => new ProposeTaskChange,
            'ProposeTaskAudit' => new ProposeTaskAudit,
            'ProposeKnowledgeChange' => new ProposeKnowledgeChange,
            'ProposeKnowledgeDocChange' => new ProposeKnowledgeDocChange,
            'ProposeDepartmentFileChange' => new ProposeDepartmentFileChange,
            'ListMyProposals' => new ListMyProposals,
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

            // 默认使用会话所选模型；有图片附件且具备看图能力时切到视觉模型
            $model = $this->modelKey();
            if (!empty($attachments)) {
                $vision = $this->visionModelKeyForImage();
                if ($vision) {
                    $model = $vision;
                }
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

            $response = $agent->prompt($prompt, provider: $lab, model: $this->modelKey(), timeout: 180);
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

    /**
     * 测试指定供应商 / 模型的连通性（管理员在设置页逐家验证）
     */
    public function testConnectionFor(?AiProvider $provider = null, ?AiModel $model = null): array
    {
        // 临时切换本次调用的供应商/模型
        $originModel = $this->model;
        if ($model) {
            $this->model = $model->loadMissing('provider');
        } elseif ($provider) {
            $pick = $provider->enabledModels()->first() ?: $provider->models()->first();
            $this->model = $pick ? $pick->loadMissing('provider') : null;
        }

        try {
            [$p, , $key] = $this->credentials();
            if (!$p || !$p->hasRealApiKey()) {
                return ['ok' => false, 'message' => '请先为该供应商填写 API Key'];
            }
            $backupKey = $this->config->api_key;
            $backupUrl = $this->config->base_url;
            // 用供应商自己的凭据覆盖本次调用（不落库）
            $this->config->forceFill(['api_key' => $key, 'base_url' => $p->base_url]);

            try {
                $result = $this->chat('你好，请只回复四个字：连接成功。');
            } finally {
                $this->config->forceFill(['api_key' => $backupKey, 'base_url' => $backupUrl]);
            }

            $which = trim(($p->name ?? '该供应商') . ' / ' . ($this->model?->name ?: ($this->model?->model_id ?: '')));
            if ($result && str_contains($result, '连接成功')) {
                return ['ok' => true, 'message' => '连接成功！' . $which . ' 回复：' . mb_substr($result, 0, 60)];
            }
            return ['ok' => false, 'message' => '连接失败，请检查 API Key、Base URL 与网络。'
                . ($result ? '（回复：' . mb_substr($result, 0, 60) . '）' : '')];
        } finally {
            $this->model = $originModel;
        }
    }

    public function providerInfo(): array
    {
        return $this->config->providerInfo();
    }
}
