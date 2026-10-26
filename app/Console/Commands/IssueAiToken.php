<?php

namespace App\Console\Commands;

use App\Models\AiDelegationToken;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 为指定用户签发 AI 委托令牌（无界面 / 运维场景使用）。
 *
 * 例：php artisan ai:issue-token 3 --name="文档生成 Agent" --scopes=read,write,download --days=90
 */
class IssueAiToken extends Command
{
    protected $signature = 'ai:issue-token
                            {user : 授权人 user_id 或 username}
                            {--name=AI Agent : 令牌名称}
                            {--scopes=read : 授权范围，逗号分隔（read/write/download）}
                            {--days=30 : 有效天数，0 表示长期}
                            {--rate=60 : 每分钟请求上限}';

    protected $description = '签发 AI 委托令牌（权限边界 = 该用户本人的权限边界）';

    public function handle(): int
    {
        $identifier = $this->argument('user');
        $user = is_numeric($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('username', $identifier)->first();

        if (!$user) {
            $this->error("用户不存在：{$identifier}");
            return self::FAILURE;
        }

        $scopes = array_filter(array_map('trim', explode(',', (string) $this->option('scopes'))));
        $allowed = array_keys(AiDelegationToken::scopeOptions());
        $invalid = array_diff($scopes, $allowed);
        if ($invalid) {
            $this->error('无效的授权范围：' . implode(',', $invalid) . '，可选：' . implode(',', $allowed));
            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        [$token, $plain] = AiDelegationToken::issue(
            $user,
            (string) $this->option('name'),
            array_values($scopes),
            $days > 0 ? $days : null,
            (int) $this->option('rate')
        );

        $this->info("已为 {$user->name}（#{$user->id}）签发令牌 #{$token->id}");
        $this->warn('明文令牌仅显示一次，请立即保存：');
        $this->line('  ' . $plain);
        $this->line('');
        $this->line('调用示例：');
        $this->line('  curl -H "X-AI-Key: $AI_API_KEY" -H "X-AI-Token: ' . $plain . '" ' . url('/api/ai/overview'));

        return self::SUCCESS;
    }
}
