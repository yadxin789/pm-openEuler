<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WecomConfig extends Model
{
    protected $fillable = [
        'corp_id', 'agent_id', 'secret', 'token', 'encoding_aes_key', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * 获取当前生效的配置
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * 激活当前配置（停用其他）
     */
    public function activate(): void
    {
        static::query()->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
