<?php

namespace App\Ai\Tools;

use App\Services\AiWorkspaceService;
use App\Services\DocumentTextExtractorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 读取当前会话工作区中某个文件的内容。
 *
 * AI 通过此工具读取 ZIP 解压后的文件内容（PDF/DOCX/XLSX/文本等），
 * 辅助用户完成设计或分析任务。
 */
class ReadWorkspaceFile implements Tool
{
    public function __construct(
        protected int $conversationId,
    ) {}

    public function description(): Stringable|string
    {
        return '读取当前会话工作区中某个文件的文本内容。支持 PDF、DOCX、XLSX、TXT、MD、CSV、JSON、PPTX 等格式。'
            . '使用前先用 ListWorkspaceFiles 查看有哪些文件。文件路径从 ListWorkspaceFiles 的结果中获取。';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) ($request['path'] ?? '');
        if ($path === '') {
            return '请提供文件路径（path），从 ListWorkspaceFiles 结果中获取。';
        }

        $workspace = app(AiWorkspaceService::class);
        $extractor = app(DocumentTextExtractorService::class);
        [$ok, $result] = $workspace->readFile($this->conversationId, $path, $extractor);

        if (!$ok) {
            return $result;
        }

        return "=== 文件：{$path} ===\n{$result}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required()->description('文件相对路径，从 ListWorkspaceFiles 结果中复制，如 "设计库/原理图.pdf"'),
        ];
    }
}
