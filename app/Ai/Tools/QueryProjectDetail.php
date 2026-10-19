<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\PmFormats;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryProjectDetail implements Tool
{
    use PmFormats;

    public function description(): Stringable|string
    {
        return '查询单个项目详情，包括项目信息与全部任务清单。参数为项目ID。';
    }

    public function handle(Request $request): Stringable|string
    {
        $id = (int) ($request['project_id'] ?? 0);
        $project = Project::with('creator')->find($id);

        if (!$project) {
            return "未找到项目 ID {$id}。";
        }

        $out = "项目详情：\n"
            . "- 名称：{$project->name}（代码 {$project->code}，版本 v{$project->version}）\n"
            . "- 产品：{$project->product_name} {$project->product_model}\n"
            . "- 发起人：{$project->creator?->name}，进度 {$project->progress}%，状态 {$this->projectStateLabel($project->status)}\n"
            . "- 周期：{$project->start_date?->toDateString()} ~ {$project->end_date?->toDateString()}";

        $tasks = $project->tasks()->with(['executor', 'auditor', 'department'])->get();
        if ($tasks->isNotEmpty()) {
            $out .= "\n\n任务清单（{$tasks->count()} 个）：";
            foreach ($tasks as $t) {
                $out .= "\n- {$t->name}｜{$t->department?->name}｜执行 {$t->executor?->name}｜审核 {$t->auditor?->name}｜{$t->start_date?->toDateString()}~{$t->end_date?->toDateString()}｜{$this->taskStateLabel($t->status)}";
            }
        }
        return $out;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required(),
        ];
    }
}
