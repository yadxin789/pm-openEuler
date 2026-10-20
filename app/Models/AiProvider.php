<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI 供应商账号（可同时配置多家，各自独立 API Key / Base URL / 启停）
 */
class AiProvider extends Model
{
    protected $table = 'ai_providers';

    protected $fillable = [
        'key', 'name', 'base_url', 'api_key', 'enabled', 'is_default', 'note',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected $hidden = ['api_key'];

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id')->orderBy('sort_order')->orderBy('id');
    }

    public function enabledModels(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id')->where('enabled', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * 是否已填写真实 Key（非占位值）
     */
    public function hasRealApiKey(): bool
    {
        return !empty($this->api_key) && !str_starts_with((string) $this->api_key, 'sk-xxxx');
    }

    /**
     * 该供应商是否真正可用（启用 + 有 Key + 至少一个启用模型）
     */
    public function isUsable(): bool
    {
        return (bool) $this->enabled && $this->hasRealApiKey();
    }

    /**
     * 脱敏 Key
     */
    public function maskedApiKey(): ?string
    {
        if (!$this->hasRealApiKey()) {
            return null;
        }
        $key = (string) $this->api_key;
        $len = mb_strlen($key);
        $head = mb_substr($key, 0, 6);
        $tail = $len > 10 ? mb_substr($key, -4) : '';
        return $head . str_repeat('*', max(8, $len - mb_strlen($head) - mb_strlen($tail))) . $tail;
    }

    /**
     * SDK Lab 标识
     */
    public function lab(): string
    {
        return AiSetting::providers()[$this->key]['lab'] ?? 'openai-compatible';
    }

    /**
     * 取 Key（按标识）
     */
    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }

    /**
     * 默认供应商
     */
    public static function default(): ?self
    {
        return static::where('is_default', true)->first() ?: static::orderBy('id')->first();
    }

    /**
     * 把本供应商设为默认（唯一）
     */
    public function markAsDefault(): void
    {
        static::where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    /**
     * 同步 AiSetting 老字段，保证其它依赖 ai_settings 的代码不会被破坏
     */
    public function syncToLegacySetting(): void
    {
        $setting = AiSetting::current();
        $defaultModel = $this->enabledModels()->where('is_default', true)->first()
            ?: $this->enabledModels()->first()
            ?: $this->models()->first();

        $setting->update(array_filter([
            'provider' => $this->key,
            'base_url' => $this->base_url,
            'api_key' => $this->hasRealApiKey() ? $this->api_key : null,
            'model' => $defaultModel?->model_id ?: $setting->model,
        ], fn ($v) => $v !== null));
    }

    /**
     * 打包本供应商预设模型
     */
    public function presetModels(): array
    {
        return AiSetting::providers()[$this->key]['models'] ?? [];
    }
}
