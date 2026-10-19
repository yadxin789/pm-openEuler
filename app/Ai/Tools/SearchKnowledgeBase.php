<?php

namespace App\Ai\Tools;

use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 文档生成 Skill 辅助工具：从知识库检索参考资料全文，供生成文档时引用。
 */
class SearchKnowledgeBase implements Tool
{
    public function description(): Stringable|string
    {
        return '从 PM 知识库检索制度、流程、规范等参考资料全文片段，用于生成文档时引用正确口径。';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request['keyword'] ?? '';
        $limit = (int) ($request['limit'] ?? 3);

        if (trim($keyword) === '') {
            return '请提供检索关键词。';
        }

        $query = KnowledgeDoc::where('type', KnowledgeDoc::TYPE_DOC)
            ->with('knowledge');

        $query->where(function ($q) use ($keyword) {
            $q->where('title', 'like', "%{$keyword}%")
              ->orWhere('md_content', 'like', "%{$keyword}%")
              ->orWhere('content', 'like', "%{$keyword}%")
              ->orWhereHas('knowledge', fn ($k) => $k->where('title', 'like', "%{$keyword}%"));
        });

        $docs = $query->limit(max(1, $limit))->get();
        if ($docs->isEmpty()) {
            return "知识库中未检索到与「{$keyword}」相关的资料。";
        }

        $out = "知识库参考资料（{$docs->count()} 条）：\n";
        foreach ($docs as $doc) {
            $out .= "【{$doc->knowledge?->title} - {$doc->title}】\n";
            $body = trim($doc->md_content ?: $doc->content ?: '');
            $out .= mb_substr($body, 0, 1500) . ($body ? "\n" : "");
        }
        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->required(),
            'limit' => $schema->integer()->min(1)->max(10),
        ];
    }
}
