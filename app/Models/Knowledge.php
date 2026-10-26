<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Knowledge extends Model
{
    use SoftDeletes;

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
        if (!$userId) {
            return false;
        }
        if ($this->admin_id == $userId) {
            return true;
        }

        return (bool) (User::find($userId)?->isAdmin() ?? false);
    }
}