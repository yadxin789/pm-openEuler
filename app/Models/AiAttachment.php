<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AiAttachment extends Model
{
    protected $table = 'ai_attachments';

    protected $fillable = [
        'conversation_id', 'message_id', 'user_id', 'type', 'original_name',
        'path', 'mime_type', 'size', 'text_extracted', 'extracted_text',
    ];

    protected $casts = [
        'size' => 'integer',
        'text_extracted' => 'boolean',
    ];

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function message()
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 是否为图片
     */
    public function isImage(): bool
    {
        return $this->type === 'image'
            || str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * 人类可读的文件大小
     */
    public function readableSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * 物理文件绝对路径
     */
    public function absolutePath(): string
    {
        return Storage::disk('ai_chat')->path($this->path);
    }

    public function exists(): bool
    {
        return Storage::disk('ai_chat')->exists($this->path);
    }

    /**
     * 图标（font-awesome）
     */
    public function icon(): string
    {
        if ($this->isImage()) {
            return 'fa-file-image-o';
        }
        $name = mb_strtolower((string) $this->original_name);
        return match (true) {
            str_ends_with($name, '.pdf') => 'fa-file-pdf-o',
            str_ends_with($name, '.xls'), str_ends_with($name, '.xlsx'), str_ends_with($name, '.csv') => 'fa-file-excel-o',
            str_ends_with($name, '.doc'), str_ends_with($name, '.docx') => 'fa-file-word-o',
            str_ends_with($name, '.ppt'), str_ends_with($name, '.pptx') => 'fa-file-powerpoint-o',
            str_ends_with($name, '.zip'), str_ends_with($name, '.rar'), str_ends_with($name, '.7z') => 'fa-file-archive-o',
            default => 'fa-file-text-o',
        };
    }
}
