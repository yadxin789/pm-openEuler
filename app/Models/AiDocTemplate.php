<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AiDocTemplate extends Model
{
    protected $table = 'ai_doc_templates';

    protected $fillable = [
        'name', 'category', 'description',
        'template_path', 'example_path', 'support_files', 'prompt_guide', 'enabled',
    ];

    protected $casts = [
        'support_files' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * AI 文档资源磁盘（storage/app/ai_docs）
     */
    public static function rootDisk(): string
    {
        return 'ai_docs';
    }

    /**
     * 模板文件内容（不存在则返回 null）
     */
    public function templateContent(): ?string
    {
        if (!$this->template_path) {
            return null;
        }
        return Storage::disk(self::rootDisk())->exists($this->template_path)
            ? Storage::disk(self::rootDisk())->get($this->template_path)
            : null;
    }

    /**
     * 范例文件内容
     */
    public function exampleContent(): ?string
    {
        if (!$this->example_path) {
            return null;
        }
        return Storage::disk(self::rootDisk())->exists($this->example_path)
            ? Storage::disk(self::rootDisk())->get($this->example_path)
            : null;
    }

    /**
     * 支援文件内容合集（返回 map: 文件名 => 内容）
     */
    public function supportContents(): array
    {
        $out = [];
        foreach ((array) $this->support_files as $path) {
            if ($path && Storage::disk(self::rootDisk())->exists($path)) {
                $out[basename($path)] = Storage::disk(self::rootDisk())->get($path);
            }
        }
        return $out;
    }
}
