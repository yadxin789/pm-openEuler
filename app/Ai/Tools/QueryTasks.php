<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\PmFormats;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryTasks implements Tool
{
    use PmFormats;

    public function description(): Stringable|string
    {
        return '查询任务列表，可按任务名称关键词、状态筛选（pending/in_progress/for_audit/overdue/completed）、执行人、项目筛选。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? null;
        $status = $request['status'] ?? null;
        $executor = $request['executor'] ?? null;
        $limit = (int) ($request['limit'] ?? 10);

        $query = Task::with(['project', 'executor', 'auditor'])->latest();
        if ($keyword) {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if ($status) {
            $map = [
                'pending' => \App\States\Task\Pending::class,
                'in_progress' => \App\States\Task\InProgress::class,
                'for_audit' => \App\States\Task\ForAudit::class,
                'overdue' => \App\States\Task\Overdue::class,
                'completed' => \App\States\Task\Completed::class,
            ];
            $query->where('status', $map[$status] ?? $status);
        }
        if ($executor) {
            $query->whereHas('executor', fn ($q) => $q->where('name', 'like', "%{$executor}%"));
        }

        $tasks = $query->limit(max(1, $limit))->get();
        if ($tasks->isEmpty()) {
            return '未找到符合条件的任务。';
        }

        $out = "任务列表（{$tasks->count()} 条）：\n";
        foreach ($tasks as $t) {
            $out .= "- {$t->name}｜项目 {$t->project?->name}｜执行人 {$t->executor?->name}｜{$t->start_date?->toDateString()}~{$t->end_date?->toDateString()}｜{$this->taskStateLabel($t->status)}\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string(),
            'status' => $schema->string()->description('pending / in_progress / for_audit / overdue / completed'),
            'executor' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
