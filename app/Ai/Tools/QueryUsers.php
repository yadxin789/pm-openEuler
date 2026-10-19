<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryUsers implements Tool
{
    public function description(): Stringable|string
    {
        return '查询系统用户列表，可按姓名/用户名关键词筛选。返回姓名、用户名、角色、部门。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? null;
        $limit = (int) ($request['limit'] ?? 20);

        $query = User::with(['role', 'department'])->where('is_active', true);
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('username', 'like', "%{$keyword}%");
            });
        }

        $users = $query->limit(max(1, $limit))->get();
        if ($users->isEmpty()) {
            return '未找到用户。';
        }

        $out = "用户列表（{$users->count()} 条）：\n";
        foreach ($users as $u) {
            $out .= "- {$u->name}（@{$u->username}）｜{$u->role?->name}｜{$u->department?->name}\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
