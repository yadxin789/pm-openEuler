<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $user_id        关联的企微用户 ID
 * @property string $media_id         企微临时素材 ID
 * @property string $msg_type         消息类型: image/file/voice/video
 * @property string|null $original_name 原始文件名
 * @property string $stored_name      系统存储文件名
 * @property string $file_path        相对路径
 * @property int $file_size           文件大小（字节）
 * @property string|null $mime_type   MIME 类型
 * @property string $status           处理状态: pending/archived/linked
 * @property string|null $remark      备注
 * @property int|null $related_id     关联目标 ID
 * @property string|null $related_type 关联目标类型
 * @property mixed $callback_data     原始回调数据
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read User|null $user
 */
class WecomFile extends Model
{
    protected $table = 'wecom_files';

    protected $fillable = [
        'user_id', 'media_id', 'msg_type', 'original_name',
        'stored_name', 'file_path', 'file_size', 'mime_type',
        'status', 'remark', 'related_id', 'related_type', 'callback_data',
    ];

    protected $casts = [
        'callback_data' => 'json',
        'file_size'     => 'integer',
    ];

    // ==================== 关系 ====================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function related()
    {
        return $this->morphTo();
    }

    // ==================== 访问器 ====================

    /** 格式化文件大小 */
    public function getFormattedSizeAttribute(): string
    {
        $size = $this->file_size;
        if ($size < 1024) return $size . ' B';
        if ($size < 1048576) return round($size / 1024, 1) . ' KB';
        if ($size < 1073741824) return round($size / 1048576, 1) . ' MB';
        return round($size / 1073741824, 2) . ' GB';
    }

    /** 消息类型中文名 */
    public function getMsgTypeNameAttribute(): string
    {
        return [
            'image' => '图片',
            'file'  => '文件',
            'voice' => '语音',
            'video' => '视频',
        ][$this->msg_type] ?? $this->msg_type;
    }

    /** 状态中文名 */
    public function getStatusNameAttribute(): string
    {
        return [
            'pending'  => '待处理',
            'archived' => '已归档',
            'linked'   => '已关联',
        ][$this->status] ?? $this->status;
    }

    /** 状态 CSS 类 */
    public function getStatusBadgeClassAttribute(): string
    {
        return [
            'pending'  => 'bg-warning',
            'archived' => 'bg-secondary',
            'linked'   => 'bg-success',
        ][$this->status] ?? 'bg-light';
    }

    /** 消息类型图标 */
    public function getMsgTypeIconAttribute(): string
    {
        return [
            'image' => 'fa-file-image-o',
            'file'  => 'fa-file-o',
            'voice' => 'fa-volume-up',
            'video' => 'fa-file-video-o',
        ][$this->msg_type] ?? 'fa-file-o';
    }

    // ==================== 辅助方法 ====================

    /** 获取完整存储路径 */
    public function getFullPath(): string
    {
        return Storage::disk('wecom_files')->path($this->file_path);
    }

    /** 是否可预览 */
    public function isPreviewable(): bool
    {
        $previewable = ['image', 'file'];
        if (!in_array($this->msg_type, $previewable)) {
            return false;
        }

        $ext = strtolower(pathinfo($this->original_name ?? $this->stored_name, PATHINFO_EXTENSION));
        $previewExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'txt'];
        return in_array($ext, $previewExts);
    }

    /** 获取下载 URL */
    public function getDownloadUrl(): string
    {
        return route('wecom.file.download', $this->id);
    }

    /** 获取预览 URL */
    public function getPreviewUrl(): string
    {
        return route('wecom.file.preview', $this->id);
    }
}
