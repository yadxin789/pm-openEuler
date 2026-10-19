<?php

namespace App\Ai\Tools;

use App\Models\Department;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryDepartments implements Tool
{
    public function description(): Stringable|string
    {
        return '查询部门列表及其成员数量。';
    }

    public function handle(Request $request): Stringable|string
    {
        $departments = Department::withCount('users')->orderBy('sort_order')->get();

        if ($departments->isEmpty()) {
            return '暂无部门。';
        }

        $out = "部门列表（{$departments->count()} 个）：\n";
        foreach ($departments as $d) {
            $out .= "- {$d->name}（{$d->users_count} 人）\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
