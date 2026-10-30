<?php

namespace App\Ai\Tools;

use App\Models\DepartmentFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 查询部门资料文件库（模板、规格书、认证文件、物料文件等）。
 */
class QueryResourceFiles implements Tool
{
    public function description(): Stringable|string
    {
        return '查询 PM 系统资料文件库（模板文件、产品规格书、元器件规格书、物料文件、安规标准、认证文件、专利文件等），可按文件名关键词和分类筛选。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? null;
        $category = $request['category'] ?? null;
        $limit = (int) ($request['limit'] ?? 10);

        $query = DepartmentFile::with(['department', 'uploader'])->latest();

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('original_name', 'like', "%{$keyword}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $files = $query->limit(max(1, $limit))->get();

        if ($files->isEmpty()) {
            return '资料文件库中未找到符合条件的文件。';
        }

        $out = "资料文件（{$files->count()} 条）：\n";
        foreach ($files as $f) {
            $catName = DepartmentFile::categoryOptions()[$f->category] ?? $f->category;
            $out .= "- {$f->original_name}（{$catName}，v{$f->version}，{$f->getFormattedSizeAttribute()}）｜部门 {$f->department?->name}｜上传 {$f->uploader?->name}｜可见性 {$f->getVisibilityNameAttribute()}\n";
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->description('文件名或原始名关键词'),
            'category' => $schema->string()->description('分类：template=模板文件, example=范例文件, product_spec=产品规格书, component_spec=元器件规格书, material=物料文件, safety=安规标准, certification=认证文件, patent=专利文件'),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }
}
