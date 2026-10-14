<?php

namespace App\Services;

use App\Models\File;
use App\Models\DepartmentFile;

class FileService
{
    /**
     * 检查文件是否可预览
     */
    public function canPreview(string $mimeType): bool
    {
        $previewable = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return in_array($mimeType, $previewable);
    }

    /**
     * 获取格式化的文件大小
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * 获取允许的文件扩展名列表
     */
    public function getAllowedExtensions(): array
    {
        return [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
            'txt', 'csv', 'zip', 'rar', '7z',
        ];
    }

    /**
     * 安全删除文件（软删除，进入回收站）
     *
     * 只标记 deleted_at，保留物理文件，管理员可在回收站恢复或彻底删除。
     */
    public function safeDelete(File $file): bool
    {
        return (bool) $file->delete();
    }

    /**
     * 获取部门文件（按可见性筛选）
     */
    public function getDepartmentFiles(?int $userId = null, ?string $visibility = null)
    {
        $query = DepartmentFile::with('uploader', 'department');

        if ($visibility) {
            $query->where('visibility', $visibility);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}
