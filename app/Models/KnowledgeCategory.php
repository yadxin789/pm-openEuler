<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeCategory extends Model
{
    protected $fillable = ['pid', 'sort', 'title', 'desc', 'status'];

    public function parent()
    {
        return $this->belongsTo(KnowledgeCategory::class, 'pid');
    }

    public function children()
    {
        return $this->hasMany(KnowledgeCategory::class, 'pid')->orderBy('sort')->orderBy('id');
    }

    public function knowledges()
    {
        return $this->hasMany(Knowledge::class, 'cate_id');
    }
}