<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CollabDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'name', 'extension', 'storage_path', 'doc_key',
        'size', 'version', 'created_by', 'updated_by',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getFileNameAttribute(): string
    {
        return $this->name . '.' . $this->extension;
    }

    public function getDisplaySizeAttribute(): string
    {
        $bytes = (int) $this->size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * 生成/刷新 OnlyOffice 文档 key（内容变更后 key 必须变化）
     */
    public function refreshDocKey(): string
    {
        $this->doc_key = md5('collab_' . $this->id . '_' . time() . '_' . random_int(1000, 9999));
        return $this->doc_key;
    }
}
