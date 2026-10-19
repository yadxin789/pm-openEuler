<?php

namespace App\Ai\Tools;

use App\Models\File;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryFiles implements Tool
{
    public function description(): Stringable|string
    {
        return '查询已交付/已上传的任务文件列表，可按文件名关键词、审核状态筛选。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? null;
        $status = $request['status'] ?? null;
        $limit = (int) ($request['limit'] ?? 10);

        $query = File::with(['task.project', 'uploader'])->latest();
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('original_name', 'like', "%{$keyword}%");
            });
        }
        if ($status) {
            $query->where('status', $status); // approved / pending / rejected
        }

        $files = $query->limit(max(1, $limit))->get();
        if ($files->isEmpty()) {
            return '未找到符合条件的文件。';
        }

        $out = "文件列表（{$files->count()} 条）：\n";
        foreach ($files as $f) {
            $out .= "- {$f->original_name}（v{$f->version}，{$f->getFormattedSizeAttribute()}）｜任务 {$f->task?->name}｜项目 {$f->task?->project?->name}｜上传 {$f->uploader?->name}｜状态 {$f->status}\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string(),
            'status' => $schema->string()->description('approved / pending / rejected'),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
