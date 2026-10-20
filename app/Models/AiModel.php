<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 模型目录：隶属于某个供应商，用户可在聊天时切换
 *
 * 注意：类名为 AiModel，需与 Illuminate\Database\Eloquent\Model 区分引入。
 */
class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'provider_id', 'model_id', 'name', 'is_vision', 'enabled', 'is_default', 'sort_order',
    ];

    protected $casts = [
        'is_vision' => 'boolean',
        'enabled' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * 用户可见名称：供应商名 + 模型名
     */
    public function fullLabel(): string
    {
        $providerName = $this->provider?->name ?: '未指定供应商';
        return $providerName . ' · ' . $this->name;
    }

    /**
     * 是否可被用户使用：模型启用 + 供应商启用且有 Key
     */
    public function isUsable(): bool
    {
        return (bool) $this->enabled && $this->provider && $this->provider->isUsable();
    }

    /**
     * 仅取用户可用的模型（含供应商信息）
     */
    public static function usable()
    {
        return static::query()
            ->with('provider')
            ->where('enabled', true)
            ->whereHas('provider', function ($q) {
                $q->where('enabled', true)->whereNotNull('api_key');
            })
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * 默认模型：优先取标记默认的可用模型，其次第一条可用模型
     */
    public static function usableDefault(): ?self
    {
        return (static::usable()->clone())->where('is_default', true)->first()
            ?: static::usable()->first();
    }

    /**
     * 设为默认模型（在同一批可用模型中唯一）
     */
    public function markAsDefault(): void
    {
        static::where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    /**
     * 会话调用时的展示名（用于消息角标）
     */
    public function badge(): string
    {
        return $this->name ?: $this->model_id;
    }
}
