<?php

namespace App\Console\Commands;

use App\Helpers\OperationLogHelper;
use App\Models\CollabDocument;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Message;
use App\Models\User;
use App\Models\WecomFile;
use App\Services\TrashService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 物理文件巡检（兜底保障）。
 *
 * 目标：任何情况下都不遗留垃圾文件、不出现「记录与文件不匹配」。
 *
 * 前端的各个删除路径已在代码层面保证清理，但只要有新的删除入口被写出来
 * 或某次删除中途失败，就可能产生孤儿文件。本命令充当最后一道防线，
 * 每天定时做一次双向核对：
 *
 *   1. 磁盘有文件、数据库无引用  → 孤儿文件（可自动清理）
 *   2. 数据库有引用、磁盘无文件  → 死记录（默认只报告，需 --purge-dead 才删）
 *   3. 空目录一律清理，避免目录树无限膨胀
 *
 * 安全阀（避免误删）：
 *   - 只扫描本系统管理的目录前缀，绝不触碰模板、备份等其它目录；
 *   - 孤儿文件有宽限期（默认 60 分钟），避免删掉「刚落盘、DB 记录还没写」的上传中文件；
 *   - 死记录占比异常时会中止清理并告警（很可能磁盘挂载掉了，此时删记录会造成灾难）。
 */
class AuditFiles extends Command
{
    protected $signature = 'files:gc
                            {--fix : 实际清理孤儿文件与空目录（不加则只检查并报告）}
                            {--purge-dead : 同时删除「库中有记录、磁盘无文件」的死记录}
                            {--grace=60 : 孤儿文件宽限期（分钟），只处理修改时间早于该时长的文件}';

    protected $description = '物理文件巡检：清理孤儿文件与空目录，核对记录与文件是否一一对应';

    /**
     * 本系统管理的目录清单（disk => 规则）。
     *
     * paths    ：精确的目录前缀（含其所有子目录）
     * patterns ：一级目录通配符
     *
     * 刻意不把整个磁盘纳入扫描范围，避免误删模板、备份等非受管文件。
     */
    protected const MANAGED = [
        'file_repository' => [
            'paths' => ['collab', 'voice_demo'],
            'patterns' => ['project-*', 'department-*'],
        ],
        'public' => [
            'paths' => ['avatars', 'chat_files', 'messages'],
            'patterns' => [],
        ],
        'wecom_files' => [
            'paths' => [''],
            'patterns' => [],
        ],
        // 历史遗留目录：当前已无任何代码往这里写文件
        'local' => [
            'paths' => ['voice_demo'],
            'patterns' => [],
        ],
    ];

    protected int $graceMinutes = 60;

    public function handle(): int
    {
        $this->graceMinutes = max(0, (int) $this->option('grace'));
        $fix = (bool) $this->option('fix');
        $purgeDead = (bool) $this->option('purge-dead');

        $this->info(($fix ? '[清理模式] ' : '[检查模式] ') . '开始巡检物理文件…');

        $references = $this->buildReferences();
        $collabIds = CollabDocument::withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->flip()->all();

        [$orphans, $missing] = $this->scan($references, $collabIds);

        $orphanCount = array_sum(array_map('count', $orphans));
        $missingCount = array_sum(array_map('count', $missing));

        $this->report('孤儿文件（磁盘有、库中无引用）', $orphans, $orphanCount);
        $this->report('死记录（库中有引用、磁盘无文件）', $missing, $missingCount);

        if ($orphanCount === 0 && $missingCount === 0) {
            $this->info('✓ 记录与物理文件完全一致，无需处理。');
            $this->cleanEmptyDirs($fix);

            return Command::SUCCESS;
        }

        if (!$fix && !$purgeDead) {
            $this->newLine();
            $this->warn('以上仅为检查结果，未做任何修改。');
            $this->line('  清理孤儿文件与空目录：php artisan files:gc --fix');
            $this->line('  同时清理死记录：      php artisan files:gc --fix --purge-dead');

            return Command::SUCCESS;
        }

        // ---- 清理孤儿文件 ----
        $deletedOrphans = 0;
        $skippedFresh = 0;

        if ($fix) {
            $this->newLine();
            foreach ($orphans as $diskName => $paths) {
                $disk = Storage::disk($diskName);

                foreach ($paths as $path) {
                    // 宽限期保护：刚落盘还没写入数据库记录的文件不删
                    $age = $this->ageInMinutes($disk, $path);
                    if ($age !== null && $age < $this->graceMinutes) {
                        $skippedFresh++;
                        continue;
                    }

                    try {
                        $disk->delete($path);
                        $deletedOrphans++;
                    } catch (\Throwable $e) {
                        $this->error("  ✗ 删除失败 {$diskName}:{$path} — {$e->getMessage()}");
                        report($e);
                    }
                }
            }

            $this->info("已清理孤儿文件：{$deletedOrphans} 个");
            if ($skippedFresh > 0) {
                $this->line("  跳过 {$skippedFresh} 个「{$this->graceMinutes} 分钟内新增」的文件（可能是正在上传中）");
            }

            $this->cleanEmptyDirs(true);
        }

        // ---- 清理死记录 ----
        $deletedMissing = 0;

        if ($purgeDead && $missingCount > 0) {
            if (!$this->deadRecordCheckSafe($missingCount)) {
                $this->error('死记录数量异常偏多，疑似磁盘未挂载或权限异常，已中止删除以防误删。请人工确认后再执行。');
            } else {
                $deletedMissing = $this->purgeDeadRecords($missing);
                $this->info("已清理死记录：{$deletedMissing} 条");
            }
        }

        if ($deletedOrphans > 0 || $deletedMissing > 0) {
            OperationLogHelper::log('文件巡检', '自动清理', sprintf(
                'files:gc 自动清理：孤儿文件 %d 个、死记录 %d 条、空目录若干',
                $deletedOrphans,
                $deletedMissing
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * 汇总所有「数据库引用的物理文件路径」：disk => [path => true]。
     */
    protected function buildReferences(): array
    {
        $refs = [
            'file_repository' => [],
            'public' => [],
            'wecom_files' => [],
            'local' => [],
        ];

        $add = function (string $disk, $path) use (&$refs) {
            $path = trim((string) $path, '/');
            if ($path !== '') {
                $refs[$disk][$path] = true;
            }
        };

        foreach (File::withTrashed()->pluck('path') as $path) {
            $add('file_repository', $path);
        }

        // 历史版本快照（改造后 path 指向独立快照文件）
        foreach (DB::table('file_versions')->pluck('path') as $path) {
            $add('file_repository', $path);
        }

        foreach (DepartmentFile::withTrashed()->pluck('path') as $path) {
            $add('file_repository', $path);
        }

        foreach (CollabDocument::withTrashed()->pluck('storage_path') as $path) {
            $add('file_repository', $path);
        }

        foreach (Message::withTrashed()->pluck('file_path') as $path) {
            $add('public', $path);
        }

        foreach (WecomFile::withTrashed()->pluck('file_path') as $path) {
            $add('wecom_files', $path);
        }

        foreach (User::withTrashed()->whereNotNull('avatar')->pluck('avatar') as $avatar) {
            // 默认头像放在 Web 静态目录 public/avatars/defaults/，不是磁盘文件
            if (str_starts_with((string) $avatar, 'defaults/')) {
                continue;
            }
            $add('public', 'avatars/' . $avatar);
        }

        return $refs;
    }

    /**
     * 扫描受管目录，返回 [孤儿文件集, 死记录集]。
     */
    protected function scan(array $references, array $collabIds): array
    {
        $orphans = [];
        $missing = [];

        foreach ($references as $diskName => $paths) {
            $disk = Storage::disk($diskName);

            foreach (array_keys($paths) as $path) {
                if (!$disk->exists($path)) {
                    $missing[$diskName][] = $path;
                }
            }
        }

        foreach (self::MANAGED as $diskName => $spec) {
            try {
                $disk = Storage::disk($diskName);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($this->listManagedFiles($disk, $spec) as $path) {
                if (isset($references[$diskName][$path])) {
                    continue;
                }

                // 协作文档的历史快照：文件名 {docId}_v{n}.{ext}，
                // 只要该文档记录仍存在，快照就不算孤儿。
                if ($diskName === 'file_repository'
                    && preg_match('#^collab/\d+/history/(\d+)_v\d+\.#', $path, $m)
                    && isset($collabIds[(int) $m[1]])) {
                    continue;
                }

                $orphans[$diskName][] = $path;
            }
        }

        return [$orphans, $missing];
    }

    /**
     * 列出受管目录下的全部文件。
     */
    protected function listManagedFiles($disk, array $spec): array
    {
        $result = [];

        $collect = function (string $dir) use ($disk, &$result) {
            try {
                foreach ($disk->allFiles($dir) as $file) {
                    $name = basename($file);
                    // 跳过隐藏文件（.gitignore 等），它们不属于业务数据
                    if (str_starts_with($name, '.')) {
                        continue;
                    }
                    $result[] = $file;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        };

        foreach ($spec['paths'] ?? [] as $dir) {
            if ($dir === '') {
                foreach ($disk->directories('') as $sub) {
                    $collect($sub);
                }
                continue;
            }

            if ($disk->exists($dir)) {
                $collect($dir);
            }
        }

        foreach ($spec['patterns'] ?? [] as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

            foreach ($disk->directories('') as $sub) {
                if (preg_match($regex, basename($sub))) {
                    $collect($sub);
                }
            }
        }

        return $result;
    }

    /**
     * 文件的「年龄」（分钟）；无法获取时返回 null（视为不新鲜，允许清理）。
     */
    protected function ageInMinutes($disk, string $path): ?int
    {
        try {
            $modified = $disk->lastModified($path);

            return $modified ? (int) floor((time() - $modified) / 60) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 死记录占比异常时的安全阀：疑似磁盘故障时不允许删记录。
     */
    protected function deadRecordCheckSafe(int $missingCount): bool
    {
        $totalFiles = File::withTrashed()->count()
            + DepartmentFile::withTrashed()->count()
            + CollabDocument::withTrashed()->count()
            + Message::withTrashed()->count();

        if ($totalFiles === 0) {
            return true;
        }

        // 超过 20% 的记录都「找不到文件」，大概率是磁盘/权限问题而非真的丢了文件
        return ($missingCount / $totalFiles) <= 0.2;
    }

    /**
     * 删除死记录：按盘符与路径反查所属记录并删除。
     */
    protected function purgeDeadRecords(array $missing): int
    {
        $deleted = 0;

        foreach ($missing as $diskName => $paths) {
            foreach ($paths as $path) {
                try {
                    if ($diskName === 'file_repository') {
                        $deleted += $this->purgeByPath(File::class, 'path', $path);
                        $deleted += $this->purgeByPath(DepartmentFile::class, 'path', $path);
                        $deleted += $this->purgeByPath(CollabDocument::class, 'storage_path', $path);
                        $deleted += $this->purgeFileVersionByPath($path);
                    } elseif ($diskName === 'public') {
                        $deleted += $this->purgeByPath(Message::class, 'file_path', $path);
                    } elseif ($diskName === 'wecom_files') {
                        $deleted += $this->purgeByPath(WecomFile::class, 'file_path', $path);
                    }
                } catch (\Throwable $e) {
                    $this->error("  ✗ 清理死记录失败 {$diskName}:{$path} — {$e->getMessage()}");
                    report($e);
                }
            }
        }

        return $deleted;
    }

    /**
     * 按路径删除某模型的全部记录（含已软删），走深度删除以顺带清理历史快照。
     */
    protected function purgeByPath(string $modelClass, string $column, string $path): int
    {
        $items = $modelClass::withTrashed()->where($column, $path)->get();
        $count = 0;

        foreach ($items as $item) {
            if ($item instanceof \App\Models\File) {
                // 任务文件：连带历史快照目录一起清理
                TrashService::forceDelete($item);
            } else {
                TrashService::forceDelete($item);
            }
            $count++;
        }

        return $count;
    }

    /**
     * 删除指向已不存在快照文件的版本记录。
     */
    protected function purgeFileVersionByPath(string $path): int
    {
        $rows = DB::table('file_versions')->where('path', $path)->get();
        $count = 0;

        foreach ($rows as $row) {
            DB::table('file_versions')->where('id', $row->id)->delete();
            $count++;
        }

        return $count;
    }

    /**
     * 清理空目录（受管范围内），避免目录树残留。
     */
    protected function cleanEmptyDirs(bool $fix): void
    {
        if (!$fix) {
            return;
        }

        $removed = 0;

        foreach (self::MANAGED as $diskName => $spec) {
            try {
                $disk = Storage::disk($diskName);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($spec['paths'] ?? [] as $dir) {
                $targets = $dir === '' ? $disk->directories('') : [$dir];

                foreach ($targets as $target) {
                    if (!$disk->exists($target)) {
                        continue;
                    }

                    $before = count($this->allDirectoriesDeep($disk, $target));
                    TrashService::removeIfEmpty($disk, $target);
                    $after = $disk->exists($target) ? count($this->allDirectoriesDeep($disk, $target)) : 0;
                    $removed += max(0, $before - $after);
                }
            }
        }

        if ($removed > 0) {
            $this->line("已清理空目录：{$removed} 个");
        }
    }

    protected function allDirectoriesDeep($disk, string $dir): array
    {
        try {
            return array_merge([$dir], $disk->allDirectories($dir));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 输出某一类问题的明细。
     */
    protected function report(string $title, array $groups, int $total): void
    {
        $this->newLine();
        $this->line("【{$title}】{$total} 个");

        if ($total === 0) {
            return;
        }

        foreach ($groups as $diskName => $paths) {
            foreach (array_slice($paths, 0, 20) as $path) {
                $this->line("  {$diskName}:{$path}");
            }

            $rest = count($paths) - 20;
            if ($rest > 0) {
                $this->line("  … 另有 {$rest} 个同类项");
            }
        }
    }
}
