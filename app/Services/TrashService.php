<?php

namespace App\Services;

use App\Models\CollabDocument;
use App\Models\Department;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TemplateTask;
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
 *   先删除数据库记录，**成功后再清理物理文件**；
 * - 物理文件字段与磁盘的对应关系集中在 PURGE_MAP 维护；
 * - 回收站分类与「归属人」关系集中在 TYPES 维护。
 *
 * 【为什么必须先删记录、后删文件】
 * 若先删文件再删记录，一旦记录删除失败（外键约束、并发等），
 * 就会出现「记录还在、文件已丢」——这是**不可恢复**的损坏。
 * 反之若记录已删、文件删除失败，只会留下「孤儿文件」，
 * 由 files:gc 巡检命令兜底清理即可。宁可留下可回收的孤儿，不可丢失数据。
 *
 * 【容器型内容的孤儿文件】
 * 数据库外键大量使用 onDelete('cascade')，而 CASCADE 由数据库执行、
 * 不经过 Eloquent，因此不会触发任何文件清理逻辑。
 * 删除项目/任务/部门这类「容器」前，必须先用 purgeDescendants()
 * 逐条彻底删除其下持有文件的子记录，再删父记录（forceDeleteDeep()）。
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
     *
     * - disk：磁盘名（见 config/filesystems.php）
     * - column：数据库里存放路径的列
     * - prefix：列值需要补的目录前缀（users.avatar 只存文件名，真实文件在 avatars/ 下）
     * - history：历史版本快照目录类型
     *     'collab'   → collab/{projectId}/history/{docId}_v{n}.{ext}
     *     'taskfile' → {任务文件目录}/history/{fileId}_v{n}.{ext}
     */
    public const PURGE_MAP = [
        CollabDocument::class => ['disk' => 'file_repository', 'column' => 'storage_path', 'history' => 'collab'],
        File::class           => ['disk' => 'file_repository', 'column' => 'path',         'history' => 'taskfile'],
        DepartmentFile::class => ['disk' => 'file_repository', 'column' => 'path'],
        Message::class        => ['disk' => 'public',          'column' => 'file_path'],
        WecomFile::class      => ['disk' => 'wecom_files',     'column' => 'file_path'],
        User::class           => ['disk' => 'public',          'column' => 'avatar', 'prefix' => 'avatars/'],
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
     * 彻底删除单条记录（不做子内容处理，调用方负责）。
     *
     * 顺序：先删数据库记录，确认成功后清理物理文件。
     */
    public static function forceDelete(Model $model): bool
    {
        // 1. 先收集待清理的物理文件（此时不删，避免记录删除失败后文件已丢）
        $targets = self::collectPurgeTargets($model);

        // 2. 删除数据库记录
        $deleted = method_exists($model, 'forceDelete')
            ? (bool) $model->forceDelete()
            : (bool) $model->delete();

        // 3. 记录确实删掉了，再清理磁盘
        if ($deleted) {
            self::purgeTargets($targets);
        }

        return $deleted;
    }

    /**
     * 深度彻底删除：先清理容器型内容的所有子记录，再删除自己。
     *
     * 用于项目 / 任务 / 部门 / 项目模板 / 知识库 / 用户，
     * 避免数据库 CASCADE 硬删子记录时留下无人引用的孤儿文件。
     */
    public static function forceDeleteDeep(Model $model): bool
    {
        self::purgeDescendants($model);

        return self::forceDelete($model);
    }

    /**
     * 逐条彻底删除「容器型」记录的直接与间接子内容。
     *
     * 子记录使用 withTrashed() 取出，同时覆盖「已进回收站」与「仍在用」两种状态，
     * 确保 CASCADE 之前其物理文件已被正确登记清理。
     */
    public static function purgeDescendants(Model $model): void
    {
        if ($model instanceof Project) {
            self::purgeProjectChildren($model);

            return;
        }

        if ($model instanceof Task) {
            self::purgeTaskChildren($model);

            return;
        }

        if ($model instanceof Department) {
            foreach (DepartmentFile::withTrashed()->where('department_id', $model->getKey())->get() as $file) {
                self::forceDelete($file);
            }

            foreach (Task::withTrashed()->where('department_id', $model->getKey())->get() as $task) {
                self::forceDeleteDeep($task);
            }

            return;
        }

        if ($model instanceof ProjectTemplate) {
            foreach (TemplateTask::where('project_template_id', $model->getKey())->get() as $templateTask) {
                self::forceDelete($templateTask);
            }

            return;
        }

        if ($model instanceof Knowledge) {
            // knowledge_docs 没有外键约束，不显式清理会残留孤儿记录
            foreach (KnowledgeDoc::withTrashed()->where('knowledge_id', $model->getKey())->get() as $doc) {
                self::forceDelete($doc);
            }

            return;
        }

        if ($model instanceof KnowledgeDoc) {
            // 知识文档可带子文档（pid 自关联），无独立物理文件，但需一并清理
            foreach (KnowledgeDoc::withTrashed()->where('pid', $model->getKey())->get() as $child) {
                self::forceDelete($child);
            }

            return;
        }

        if ($model instanceof User) {
            self::purgeUserAssets($model);
        }
    }

    /**
     * 项目下的协作文档（含历史快照）、聊天记录（含附件）、任务与任务文件。
     */
    protected static function purgeProjectChildren(Project $project): void
    {
        foreach (CollabDocument::withTrashed()->where('project_id', $project->getKey())->get() as $doc) {
            self::forceDelete($doc);
        }

        foreach (Message::withTrashed()->where('project_id', $project->getKey())->get() as $message) {
            self::forceDelete($message);
        }

        foreach (Task::withTrashed()->where('project_id', $project->getKey())->get() as $task) {
            self::forceDeleteDeep($task);
        }
    }

    /**
     * 任务下的全部文件（含待审核、已驳回、历史版本）。
     */
    protected static function purgeTaskChildren(Task $task): void
    {
        foreach (File::withTrashed()->where('task_id', $task->getKey())->get() as $file) {
            self::forceDelete($file);
        }
    }

    /**
     * 彻底删除用户前，清理其名下所有会连带消失的物理文件。
     *
     * users 被硬删时会触发大量 CASCADE（files / messages / projects / tasks …），
     * 这些级联不经过 Eloquent，必须在这里提前把文件清干净。
     */
    public static function purgeUserAssets(User $user): void
    {
        // 头像（users.avatar 只存文件名，真实文件在 avatars/ 下）
        if (!empty($user->avatar)) {
            self::purgeTargets([[
                'disk' => 'public',
                'path' => 'avatars/' . $user->avatar,
            ]]);
        }

        // 该用户上传 / 审核的任务文件
        $files = File::withTrashed()
            ->where('uploader_id', $user->getKey())
            ->orWhere('auditor_id', $user->getKey())
            ->get();
        foreach ($files as $file) {
            self::forceDelete($file);
        }

        // 该用户发送 / 接收的聊天记录（含附件）
        $messages = Message::withTrashed()
            ->where('user_id', $user->getKey())
            ->orWhere('receiver_id', $user->getKey())
            ->get();
        foreach ($messages as $message) {
            self::forceDelete($message);
        }

        // 该用户创建或参与的任务
        $tasks = Task::withTrashed()
            ->where('executor_id', $user->getKey())
            ->orWhere('auditor_id', $user->getKey())
            ->get();
        foreach ($tasks as $task) {
            self::forceDeleteDeep($task);
        }

        // 该用户创建的项目（级联其下所有内容）
        foreach (Project::withTrashed()->where('creator_id', $user->getKey())->get() as $project) {
            self::forceDeleteDeep($project);
        }

        // 该用户创建的协作文档
        foreach (CollabDocument::withTrashed()->where('created_by', $user->getKey())->get() as $doc) {
            self::forceDelete($doc);
        }
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

    // ------------------------------------------------------------------
    // 物理文件清理
    // ------------------------------------------------------------------

    /**
     * 收集模型对应的物理文件清理目标（不执行删除）。
     *
     * 返回形如：
     *   ['disk' => 'file_repository', 'path' => 'project-1/task-3/xxx.pdf']
     *   ['disk' => 'file_repository', 'dir' => '.../history', 'prefix' => '12_v']
     */
    public static function collectPurgeTargets(Model $model): array
    {
        $config = self::PURGE_MAP[get_class($model)] ?? null;
        if (!$config) {
            return [];
        }

        $targets = [];

        $relative = $model->getAttribute($config['column']);
        if (!empty($relative)) {
            $targets[] = [
                'disk' => $config['disk'],
                'path' => ($config['prefix'] ?? '') . $relative,
            ];
        }

        if (!empty($config['history'])) {
            $dir = self::historyDir($model, $config['history']);
            if ($dir) {
                $targets[] = [
                    'disk' => $config['disk'],
                    'dir' => $dir,
                    'prefix' => $model->getKey() . '_v',
                ];
            }
        }

        return $targets;
    }

    /**
     * 按清理目标删除磁盘文件；单个失败不影响其余，异常只记录不上抛。
     */
    public static function purgeTargets(array $targets): void
    {
        foreach ($targets as $target) {
            try {
                $disk = Storage::disk($target['disk']);

                if (!empty($target['path']) && $disk->exists($target['path'])) {
                    $disk->delete($target['path']);
                }

                if (!empty($target['dir'])) {
                    self::purgeDirByPrefix($disk, $target['dir'], $target['prefix'] ?? '');
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * 兼容旧调用：清理模型关联的物理文件。
     */
    public static function purgeFiles(Model $model): void
    {
        self::purgeTargets(self::collectPurgeTargets($model));
    }

    /**
     * 删除目录下匹配前缀的文件；目录清空后一并移除，不留空目录。
     */
    public static function purgeDirByPrefix($disk, string $dir, string $prefix = ''): void
    {
        if (!$disk->exists($dir)) {
            return;
        }

        foreach ($disk->files($dir) as $file) {
            if ($prefix === '' || str_starts_with(basename($file), $prefix)) {
                $disk->delete($file);
            }
        }

        self::removeIfEmpty($disk, $dir);
    }

    /**
     * 目录及其各级父目录为空时逐级删除，避免残留空目录。
     */
    public static function removeIfEmpty($disk, string $dir): void
    {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return;
        }

        try {
            while ($dir !== '' && $dir !== '.') {
                if (!$disk->exists($dir)) {
                    $dir = dirname($dir);
                    continue;
                }

                if (!empty($disk->files($dir)) || !empty($disk->directories($dir))) {
                    break;
                }

                $disk->deleteDirectory($dir);
                $dir = dirname($dir);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 计算模型历史快照所在目录。
     */
    protected static function historyDir(Model $model, string $type): ?string
    {
        if ($type === 'collab') {
            $projectId = $model->getAttribute('project_id');

            return $projectId ? 'collab/' . $projectId . '/history' : null;
        }

        if ($type === 'taskfile') {
            // 任务文件目录为 project-{pid}/task-{tid}/，直接从 path 推算，无需关联查询
            $path = (string) $model->getAttribute('path');

            return $path !== '' ? dirname($path) . '/history' : null;
        }

        return null;
    }

    /**
     * 裁剪任务的历史版本快照，每个任务只保留最近 N 个版本，避免无限膨胀。
     *
     * 保留上限由 config('pm.file_version_keep') 控制（默认 3）。
     * 以 file_versions 记录为准（而非解析文件名），同时删除过期的快照文件与记录，
     * 保证「记录与文件」始终一一对应。
     */
    public static function pruneFileHistory(Task $task): void
    {
        $keep = max(1, (int) config('pm.file_version_keep', 3));

        $versions = DB::table('file_versions')
            ->join('files', 'files.id', '=', 'file_versions.file_id')
            ->where('files.task_id', $task->getKey())
            ->orderByDesc('file_versions.created_at')
            ->orderByDesc('file_versions.id')
            ->get(['file_versions.id', 'file_versions.path']);

        if ($versions->count() <= $keep) {
            return;
        }

        $disk = Storage::disk('file_repository');

        foreach ($versions->slice($keep) as $obsolete) {
            try {
                if (!empty($obsolete->path) && $disk->exists($obsolete->path)) {
                    $disk->delete($obsolete->path);
                }

                DB::table('file_versions')->where('id', $obsolete->id)->delete();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        self::removeIfEmpty($disk, 'project-' . $task->project_id . '/task-' . $task->getKey() . '/history');
    }
}
