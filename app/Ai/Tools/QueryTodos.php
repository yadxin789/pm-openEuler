<?php

namespace App\Ai\Tools;

use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryTodos implements Tool
{
    public function description(): Stringable|string
    {
        return '查询待办事项列表，可按是否完成筛选。';
    }

    public function handle(Request $request): Stringable|string
    {
        $completed = $request['completed'] ?? null; // true/false
        $limit = (int) ($request['limit'] ?? 20);

        $query = Todo::with('user')->latest();
        if ($completed === true || $completed === 'true' || $completed === 'completed') {
            $query->where('status', 'completed');
        } elseif ($completed === false || $completed === 'false' || $completed === 'pending') {
            $query->where('status', '!=', 'completed');
        }

        $todos = $query->limit(max(1, $limit))->get();
        if ($todos->isEmpty()) {
            return '暂无待办事项。';
        }

        $out = "待办事项（{$todos->count()} 条）：\n";
        foreach ($todos as $t) {
            $out .= "- [" . ($t->status === 'completed' ? '已完成' : '待办') . "] {$t->title}（{$t->user?->name}）\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'completed' => $schema->boolean(),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
