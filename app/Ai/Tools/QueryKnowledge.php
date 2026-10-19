<?php

namespace App\Ai\Tools;

use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryKnowledge implements Tool
{
    public function description(): Stringable|string
    {
        return '搜索知识库，可按标题关键词查找知识条目及其文档内容。用于回答制度、流程、规范类问题。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? null;
        $limit = (int) ($request['limit'] ?? 5);

        $query = Knowledge::with(['category', 'author', 'docs' => function ($q) {
            $q->where('type', KnowledgeDoc::TYPE_DOC);
        }]);

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                  ->orWhere('desc', 'like', "%{$keyword}%")
                  ->orWhereHas('docs', fn ($d) => $d->where('title', 'like', "%{$keyword}%"));
            });
        }

        $knowledges = $query->limit(max(1, $limit))->get();
        if ($knowledges->isEmpty()) {
            return '知识库中未找到相关内容。';
        }

        $out = "知识库命中（{$knowledges->count()} 条）：\n";
        foreach ($knowledges as $k) {
            $out .= "- [{$k->category?->name}] {$k->title}（作者 {$k->author?->name}）\n";
            foreach ($k->docs->take(3) as $doc) {
                $snippet = mb_substr(strip_tags($doc->md_content ?: $doc->content ?: ''), 0, 80);
                $out .= "    · {$doc->title}：" . ($snippet ?: '（无正文）') . "\n";
            }
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->required(),
            'limit' => $schema->integer()->min(1)->max(20),
        ];
    }
}
