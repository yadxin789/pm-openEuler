<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\PmFormats;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryProjects implements Tool
{
    use PmFormats;

    public function description(): Stringable|string
    {
        return '查询项目列表，可按名称关键词、状态筛选。返回项目名称、代码、版本、产品、进度、起止日期、状态。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? null;
        $status = $request['status'] ?? null;
        $limit = (int) ($request['limit'] ?? 10);

        $query = Project::with('creator')->latest();
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('code', 'like', "%{$keyword}%")
                  ->orWhere('product_name', 'like', "%{$keyword}%");
            });
        }
        if ($status) {
            $map = [
                'in_progress' => \App\States\Project\InProgress::class,
                'overdue' => \App\States\Project\Overdue::class,
                'completed' => \App\States\Project\Completed::class,
                'suspended' => \App\States\Project\Suspended::class,
                'not_started' => \App\States\Project\NotStarted::class,
            ];
            $query->where('status', $map[$status] ?? $status);
        }

        $projects = $query->limit(max(1, $limit))->get();
        if ($projects->isEmpty()) {
            return '未找到符合条件的项目。';
        }

        $out = "项目列表（{$projects->count()} 条）：\n";
        foreach ($projects as $p) {
            $creator = $p->creator?->name ?? '—';
            $out .= "- [{$p->code}] {$p->name}（{$p->product_name} {$p->product_model}，版本 v{$p->version}，进度 {$p->progress}%，发起人 {$creator}，状态 {$this->projectStateLabel($p->status)}，{$p->start_date?->toDateString()} ~ {$p->end_date?->toDateString()}）\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string(),
            'status' => $schema->string()->description('in_progress / overdue / completed / suspended / not_started'),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
