<?php

namespace App\Services;

use App\Models\CollabDocument;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\User;
use App\Models\WecomFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 统一的软删除 / 恢复 / 彻底删除入口。
 *
 * 设计约定（全系统统一）：
 * - 软删除：只写 deleted_at 与 deleted_by，**绝不触碰物理文件**，保证可恢复；
 * - 恢复：清空 deleted_at 与 deleted_by；
 * - 彻底删除：仅管理员可触发（由 AdminTrashController 保证权限），
 *   先清理物理文件，再硬删数据库记录；
 * - 物理文件字段与磁盘的对应关系集中在 PURGE_MAP 维护；
 * - 回收站分类与「归属人」关系集中在 TYPES 维护。
 *
 * 个人回收站可见范围（scopeForUser）：
 *   我删除的（deleted_by = 我） 或 我创建的（归属人 = 我）。
 *   因此管理员删除的内容，创建人依然能在自己的回收站里恢复。
 */
class TrashService
{
    /**
     * 回收站分类配置。
     *
     * - model：对应模型
     * - label / icon：界面展示
     * - field：列表中用于展示名称的字段
     * - owner：该模型的「创建人 / 归属人」列名；null 表示没有归属人列，
     *          此时个人回收站只能按 deleted_by（谁删的）匹配。
     */
    public const TYPES = [
        'collab-documents'  => ['model' => CollabDocument::class,  'label' => '协作文档', 'icon' => 'fa-file-text-o', 'field' => 'name',          'owner' => 'created_by'],
        'projects'          => ['model' => Project::class,         'label' => '项目',     'icon' => 'fa-folder-o',    'field' => 'name',          'owner' => 'creator_id'],
        'tasks'             => ['model' => Task::class,            'label' => '任务',     'icon' => 'fa-tasks',       'field' => 'name',          'owner' => null],
        'files'             => ['model' => File::class,            'label' => '任务文件', 'icon' => 'fa-paperclip',   'field' => 'original_name', 'owner' => 'uploader_id'],
        'department-files'  => ['model' => DepartmentFile::class,  'label' => '资料文件', 'icon' => 'fa-folder-open', 'field' => 'original_name', 'owner' => 'uploader_id'],
        'messages'          => ['model' => Message::class,         'label' => '聊天记录', 'icon' => 'fa-comments',    'field' => 'content',       'owner' => 'user_id'],
        'wecom-files'       => ['model' => WecomFile::class,       'label' => '企微文件', 'icon' => 'fa-inbox',       'field' => 'original_name', 'owner' => 'user_id'],
        'notifications'     => ['model' => Notification::class,    'label' => '通知',     'icon' => 'fa-bell',        'field' => 'title',         'owner' => 'user_id'],
        'knowledges'        => ['model' => Knowledge::class,       'label' => '知识库',   'icon' => 'fa-book',        'field' => 'title',         'owner' => 'admin_id'],
        'knowledge-docs'    => ['model' => KnowledgeDoc::class,    'label' => '知识文档', 'icon' => 'fa-file-text-o', 'field' => 'title',         'owner' => null],
        'project-templates' => ['model' => ProjectTemplate::class, 'label' => '项目模板', 'icon' => 'fa-clone',       'field' => 'name',          'owner' => 'user_id'],
    ];

    /**
     * 模型 => 物理文件所在磁盘与字段。
     * history_dir = true 表示该模型还带有附加的历史版本快照目录，彻底删除时需一并清理。
     */
    public const PURGE_MAP = [
        CollabDocument::class => ['disk' => 'file_repository', 'column' => 'storage_path', 'history_dir' => true],
        File::class           => ['disk' => 'file_repository', 'column' => 'path'],
        DepartmentFile::class => ['disk' => 'file_repository', 'column' => 'path'],
        Message::class        => ['disk' => 'public',          'column' => 'file_path'],
        WecomFile::class      => ['disk' => 'wecom_files',     'column' => 'file_path'],
        User::class           => ['disk' => 'public',          'column' => 'avatar'],
    ];

    /**
     * 校验并取出分类配置。
     */
    public static function typeOrFail(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404, '未知的回收站分类');

        return self::TYPES[$type];
    }

    /**
     * 限定为「某个人的回收站」范围：我删除的 或 我创建的。
     *
     * 传入的查询必须已经只包含软删记录（onlyTrashed()）。
     */
    public static function scopeForUser(Builder $query, string $type, int $userId): Builder
    {
        $owner = self::TYPES[$type]['owner'] ?? null;

        return $query->where(function (Builder $q) use ($owner, $userId) {
            $q->where('deleted_by', $userId);

            if ($owner) {
                $q->orWhere($owner, $userId);
            }
        });
    }

    /**
     * 统计某人的个人回收站条数（用于导航栏徽章）。
     */
    public static function countForUser(int $userId): int
    {
        $total = 0;

        foreach (self::TYPES as $type => $config) {
            $total += self::scopeForUser($config['model']::onlyTrashed(), $type, $userId)->count();
        }

        return $total;
    }

    /**
     * 生成记录在回收站里的展示名称。
     */
    public static function displayName(string $type, Model $model): string
    {
        $field = self::TYPES[$type]['field'] ?? 'name';

        if ($type === 'collab-documents') {
            $ext = !empty($model->extension) ? '.' . $model->extension : '';

            return Str::limit(($model->name ?? '未命名') . $ext, 60);
        }

        if ($type === 'messages') {
            $text = trim((string) ($model->content ?? ''));
            if ($text === '') {
                $fileName = (string) ($model->file_name ?? '');
                $text = $fileName !== '' ? '[文件] ' . $fileName : '（空消息）';
            }

            return Str::limit($text, 60);
        }

        return Str::limit((string) ($model->getAttribute($field) ?? '未命名'), 60);
    }

    /**
     * 软删除：只标记删除时间与删除人，保留物理文件以便恢复。
     * deleted_by 由模型 deleting 事件统一写入（见 AppServiceProvider）。
     */
    public static function softDelete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * 恢复软删除的记录，并清空「删除人」。
     */
    public static function restore(Model $model): bool
    {
        if (!method_exists($model, 'restore')) {
            return false;
        }

        $restored = (bool) $model->restore();

        if ($restored) {
            self::clearDeletedBy($model);
        }

        return $restored;
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
     * 清空记录的 deleted_by（恢复后不应再保留删除人信息）。
     */
    protected static function clearDeletedBy(Model $model): void
    {
        if (!self::hasDeletedByColumn($model)) {
            return;
        }

        try {
            DB::table($model->getTable())
                ->where($model->getKeyName(), $model->getKey())
                ->update(['deleted_by' => null]);

            $model->deleted_by = null;
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 判断模型对应的表是否存在 deleted_by 列（每张表只在单次请求内查询一次）。
     */
    public static function hasDeletedByColumn(Model $model): bool
    {
        static $cache = [];

        $table = $model->getTable();

        if (!array_key_exists($table, $cache)) {
            try {
                $cache[$table] = Schema::hasColumn($table, 'deleted_by');
            } catch (\Throwable $e) {
                $cache[$table] = false;
            }
        }

        return $cache[$table];
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

        try {
            $disk = Storage::disk($config['disk']);

            // 主文件
            $relative = $model->getAttribute($config['column']);
            if ($relative && $disk->exists($relative)) {
                $disk->delete($relative);
            }

            // 附加的历史版本快照目录（协作文档）
            if (!empty($config['history_dir']) && $model instanceof CollabDocument) {
                self::purgeHistorySnapshots($disk, $model);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 清理协作文档的历史版本快照 collab/{projectId}/history/{docId}_v*.{ext}，
     * 避免彻底删除后残留孤儿文件；目录清空后一并移除。
     */
    protected static function purgeHistorySnapshots($disk, CollabDocument $doc): void
    {
        $dir = 'collab/' . $doc->project_id . '/history';
        if (!$disk->exists($dir)) {
            return;
        }

        $prefix = $doc->id . '_v';
        foreach ($disk->files($dir) as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                $disk->delete($file);
            }
        }

        if (empty($disk->files($dir)) && empty($disk->directories($dir))) {
            $disk->deleteDirectory($dir);
        }
    }
}
