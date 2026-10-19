<?php

namespace App\Ai\Tools;

use App\Models\AiDocTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * 文档生成 Skill 核心工具：按模板读取「模板文件 + 范例文件 + 支援文件 + 生成指引」。
 */
class GetDocTemplate implements Tool
{
    public function description(): Stringable|string
    {
        return '按文档类型模板ID读取用于生成文档所需的模板文件、范例文件、支援文件及生成指引。生成任何文档前必须先调用本工具获取模板内容。';
    }

    public function handle(Request $request): Stringable|string
    {
        $templateId = (int) ($request['template_id'] ?? 0);
        $template = AiDocTemplate::where('enabled', true)->find($templateId);

        if (!$template) {
            return "未找到文档模板 ID {$templateId}（或已停用）。";
        }

        $out = "文档模板：{$template->name}（分类：{$template->category}）\n";
        $out .= "用途说明：{$template->description}\n\n";

        if ($guide = $template->prompt_guide) {
            $out .= "【生成指引】\n{$guide}\n\n";
        }

        if ($tpl = $template->templateContent()) {
            $out .= "【模板文件内容】\n{$tpl}\n\n";
        } else {
            $out .= "【模板文件】无（请依据用途说明与指引自行组织结构）\n\n";
        }

        if ($example = $template->exampleContent()) {
            $out .= "【范例文件内容】\n{$example}\n\n";
        }

        foreach ($template->supportContents() as $fileName => $content) {
            $out .= "【支援文件：{$fileName}】\n{$content}\n\n";
        }

        return rtrim($out);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->integer()->required(),
        ];
    }
}
