<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    protected $table = 'knowledges';

    protected $fillable = [
        'admin_id', 'cate_id', 'title', 'desc', 'is_share', 'read', 'sort',
    ];

    protected $casts = [
        'is_share' => 'boolean',
        'read' => 'integer',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function category()
    {
        return $this->belongsTo(KnowledgeCategory::class, 'cate_id');
    }

    public function docs()
    {
        return $this->hasMany(KnowledgeDoc::class, 'knowledge_id');
    }

    public function rootDocs()
    {
        return $this->docs()->where('pid', 0)->orderBy('sort')->orderBy('id');
    }

    public function isVisibleTo(?int $userId): bool
    {
        return (bool) $this->is_share || $this->admin_id == $userId;
    }

    public function isEditableBy(?int $userId): bool
    {
        return $this->admin_id == $userId || auth()->user()->isAdmin();
    }
}