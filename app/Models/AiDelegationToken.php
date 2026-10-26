<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户签发给 AI 的委托令牌。
 *
 * 令牌本身不携带任何「额外」权限：AI 拿着它能做什么，完全取决于签发者本人能做什么。
 * 权限判定统一走 AiDelegationService，复用业务模型（Task / File / Knowledge ...）自身的鉴权方法。
 */
class AiDelegationToken extends Model
{
    protected $fillable = [
        'user_id', 'name', 'token_hash', 'token_prefix', 'scopes',
        'rate_limit_per_minute', 'expires_at', 'last_used_at', 'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'rate_limit_per_minute' => 'integer',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'write';
    public const SCOPE_DOWNLOAD = 'download';

    public static function scopeOptions(): array
    {
        return [
            self::SCOPE_READ => '读取（查询项目 / 任务 / 文件 / 知识库）',
            self::SCOPE_WRITE => '编辑（仍需逐条提案，经本人确认后生效）',
            self::SCOPE_DOWNLOAD => '下载文件正文（受本人下载权限约束）',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 生成一枚令牌，返回 [model, 明文]。明文只在生成时出现一次。
     */
    public static function issue(User $user, string $name, array $scopes, ?int $days = 30, int $rateLimit = 60): array
    {
        $plain = 'pmat_' . bin2hex(random_bytes(24));

        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
            'scopes' => array_values(array_unique($scopes)),
            'rate_limit_per_minute' => $rateLimit,
            'expires_at' => $days ? now()->addDays($days) : null,
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return (bool) $this->user?->is_active;
    }

    public function touchUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
