<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 多供应商 / 多模型支持
 *
 * - ai_providers：管理员可同时配置多家 AI 供应商（各自独立 API Key / Base URL / 启停）
 * - ai_models  ：每个供应商下可启用多个模型，用户可在聊天时随时切换
 * - ai_conversations.model_id：会话记住当前使用的模型
 *
 * 迁移会把 ai_settings 中现有的「单供应商配置」自动导入为新表的第一条记录，
 * 保证老数据不丢失、无需管理员重新配置。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ========== 供应商账号 ==========
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique()->comment('供应商标识：deepseek/qwen/doubao/hunyuan');
            $table->string('name', 100);
            $table->string('base_url', 500)->nullable();
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(true)->comment('是否启用该供应商');
            $table->boolean('is_default')->default(false)->comment('是否默认供应商');
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        // ========== 模型目录 ==========
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model_id', 120)->comment('调用时使用的模型标识，如 deepseek-chat');
            $table->string('name', 150)->comment('展示名称');
            $table->boolean('is_vision')->default(false)->comment('是否支持看图/图片理解');
            $table->boolean('enabled')->default(true)->comment('是否开放给用户选择');
            $table->boolean('is_default')->default(false)->comment('是否默认模型');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['provider_id', 'model_id']);
        });

        // ========== 会话记住当前模型 ==========
        if (Schema::hasTable('ai_conversations') && !Schema::hasColumn('ai_conversations', 'model_id')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                $table->foreignId('model_id')->nullable()->after('skill_slug')->nullOnDelete();
            });
        }

        $this->seedFromLegacySettings();
    }

    /**
     * 把 ai_settings 单供应商配置导入新表（幂等）
     */
    protected function seedFromLegacySettings(): void
    {
        if (!Schema::hasTable('ai_settings')) {
            return;
        }
        if (DB::table('ai_providers')->count() > 0) {
            return;
        }

        $now = now();
        $setting = DB::table('ai_settings')->orderBy('id')->first();
        $currentProvider = $setting->provider ?? 'deepseek';
        $currentModel = $setting->model ?? null;
        $currentKey = $setting->api_key ?? null;
        $currentBaseUrl = $setting->base_url ?? null;
        $keyIsReal = !empty($currentKey) && !str_starts_with((string) $currentKey, 'sk-xxxx');

        // AiSetting::providers() 为静态目录，迁移里用同名数组避免依赖模型层
        $catalog = $this->presetProviders();

        foreach ($catalog as $key => $p) {
            $isCurrent = $key === $currentProvider;
            $providerId = DB::table('ai_providers')->insertGetId([
                'key' => $key,
                'name' => $p['name'],
                'base_url' => $isCurrent && $currentBaseUrl ? $currentBaseUrl : $p['base_url'],
                'api_key' => $isCurrent && $keyIsReal ? $currentKey : null,
                'enabled' => $isCurrent && $keyIsReal,
                'is_default' => $isCurrent,
                'note' => $keyIsReal && $isCurrent ? '由旧版配置自动迁移' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order = 0;
            foreach ($p['models'] as $modelId => $label) {
                DB::table('ai_models')->insert([
                    'provider_id' => $providerId,
                    'model_id' => $modelId,
                    'name' => $label,
                    'is_vision' => false,
                    'enabled' => true,
                    'is_default' => $isCurrent && $modelId === $currentModel,
                    'sort_order' => $order++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * 与 App\Models\AiSetting::providers() 保持一致的预设目录（迁移内自包含）
     */
    protected function presetProviders(): array
    {
        return [
            'deepseek' => [
                'name' => 'DeepSeek（深度求索）',
                'base_url' => 'https://api.deepseek.com/v1',
                'models' => [
                    'deepseek-chat' => 'DeepSeek-V3（通用对话）',
                    'deepseek-reasoner' => 'DeepSeek-R1（推理模型）',
                ],
            ],
            'qwen' => [
                'name' => '通义千问（阿里云）',
                'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'models' => [
                    'qwen-turbo' => 'Qwen-Turbo（快速低成本）',
                    'qwen-plus' => 'Qwen-Plus（均衡）',
                    'qwen-max' => 'Qwen-Max（最强）',
                ],
            ],
            'doubao' => [
                'name' => '豆包（字节跳动）',
                'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'models' => [
                    'doubao-pro-4k' => 'Doubao-Pro-4k',
                    'doubao-pro-32k' => 'Doubao-Pro-32k',
                    'doubao-pro-128k' => 'Doubao-Pro-128k',
                ],
            ],
            'hunyuan' => [
                'name' => '腾讯混元',
                'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',
                'models' => [
                    'hunyuan-lite' => 'Hunyuan-Lite（免费）',
                    'hunyuan-pro' => 'Hunyuan-Pro',
                    'hunyuan-standard' => 'Hunyuan-Standard',
                ],
            ],
        ];
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_conversations') && Schema::hasColumn('ai_conversations', 'model_id')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                $table->dropForeign(['model_id']);
                $table->dropColumn('model_id');
            });
        }
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
