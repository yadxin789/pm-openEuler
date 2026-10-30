<?php

namespace App\Ai\Tools;

use App\Services\AiWorkspaceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 列出当前会话工作区中已解压的文件。
 *
 * 当用户上传了 ZIP 设计资料库等压缩包后，AI 调用此工具查看里面有哪些文件。
 */
class ListWorkspaceFiles implements Tool
{
    public function __construct(
        protected int $conversationId,
    ) {}

    public function description(): Stringable|string
    {
        return '列出当前会话工作区中已解压的文件（通常来自用户上传的ZIP压缩包）。'
            . '返回文件路径、大小和类型。当用户上传了压缩包并询问里面有什么、或需要浏览设计资料库时，使用此工具。';
    }

    public function handle(Request $request): Stringable|string
    {
        $subDir = (string) ($request['sub_dir'] ?? '');
        $workspace = app(AiWorkspaceService::class);
        $files = $workspace->listFiles($this->conversationId, $subDir, 500);

        if (empty($files)) {
            return '当前工作区没有已解压的文件。如果用户刚上传了ZIP，请确认是否解压成功。';
        }

        // 按扩展名分组统计
        $byExt = [];
        foreach ($files as $f) {
            $ext = $f['type'] ?: 'unknown';
            $byExt[$ext] = ($byExt[$ext] ?? 0) + 1;
        }

        $summary = "工作区共 {$this->conversationId} 个文件：\n";
        foreach ($byExt as $ext => $count) {
            $summary .= "  - .{$ext}：{$count} 个\n";
        }
        $summary .= "\n文件列表：\n";

        $shown = 0;
        foreach ($files as $f) {
            $size = $f['size'] >= 1024 ? round($f['size'] / 1024, 1) . 'KB' : $f['size'] . 'B';
            $summary .= "  - {$f['path']}（{$size}）\n";
            $shown++;
            if ($shown >= 200) {
                $summary .= "  …（还有 " . (count($files) - 200) . " 个文件未列出，可用 sub_dir 缩小范围）\n";
                break;
            }
        }

        return rtrim($summary);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sub_dir' => $schema->string()->description('可选：只列出某个子目录下的文件，如 "设计库/原理图"。留空列出全部。'),
        ];
    }
}
