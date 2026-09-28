<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeDoc extends Model
{
    protected $fillable = [
        'knowledge_id', 'pid', 'type', 'title', 'md_content', 'content', 'link', 'file_ids', 'read', 'sort',
    ];

    protected $casts = [
        'type' => 'integer',
        'read' => 'integer',
    ];

    public const TYPE_DOC = 1;
    public const TYPE_CHAPTER = 2;
    public const TYPE_LINK = 3;

    public static function typeLabel(int $type): string
    {
        return match ($type) {
            self::TYPE_DOC => '文档',
            self::TYPE_CHAPTER => '章节',
            self::TYPE_LINK => '链接',
            default => '文档',
        };
    }

    public function knowledge()
    {
        return $this->belongsTo(Knowledge::class, 'knowledge_id');
    }

    public function parent()
    {
        return $this->belongsTo(KnowledgeDoc::class, 'pid');
    }

    public function children()
    {
        return $this->hasMany(KnowledgeDoc::class, 'pid')->orderBy('sort')->orderBy('id');
    }
}