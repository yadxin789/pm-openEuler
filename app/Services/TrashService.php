<?php

namespace App\Services;

use App\Models\CollabDocument;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Message;
use App\Models\User;
use App\Models\WecomFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * 统一的软删除 / 恢复 / 彻底删除入口。
 *
 * 设计约定（全系统统一）：
 * - 软删除：只写 deleted_at，**绝不触碰物理文件**，保证管理员可恢复；
 * - 恢复：清空 deleted_at；
 * - 彻底删除：仅管理员可触发（由 AdminTrashController 保证权限），
 *   先清理物理文件，再硬删数据库记录；
 * - 物理文件字段与磁盘的对应关系集中在 PURGE_MAP 维护。
 */
class TrashService
{
    /**
     * 模型 => 物理文件所在磁盘与字段。
     */
    public const PURGE_MAP = [
        CollabDocument::class => ['disk' => 'file_repository', 'column' => 'storage_path'],
        File::class           => ['disk' => 'file_repository', 'column' => 'path'],
        DepartmentFile::class => ['disk' => 'file_repository', 'column' => 'path'],
        Message::class        => ['disk' => 'public',          'column' => 'file_path'],
        WecomFile::class      => ['disk' => 'wecom_files',     'column' => 'file_path'],
        User::class           => ['disk' => 'public',          'column' => 'avatar'],
    ];

    /**
     * 软删除：只标记删除时间，保留物理文件，等待管理员在回收站恢复或彻底删除。
     */
    public static function softDelete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * 恢复软删除的记录。
     */
    public static function restore(Model $model): bool
    {
        if (!method_exists($model, 'restore')) {
            return false;
        }

        return (bool) $model->restore();
    }

    /**
     * 彻底删除：先清理物理文件，再硬删数据库记录。仅管理员可调用。
     */
    public static function forceDelete(Model $model): bool
    {
        self::purgeFiles($model);

        if (method_exists($model, 'forceDelete')) {
            return (bool) $model->forceDelete();
        }

        return (bool) $model->delete();
    }

    /**
     * 清理模型关联的物理文件。物理文件缺失时不阻断记录的删除。
     */
    public static function purgeFiles(Model $model): void
    {
        $config = self::PURGE_MAP[get_class($model)] ?? null;
        if (!$config) {
            return;
        }

        $relative = $model->getAttribute($config['column']);
        if (!$relative) {
            return;
        }

        try {
            $disk = Storage::disk($config['disk']);
            if ($disk->exists($relative)) {
                $disk->delete($relative);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
