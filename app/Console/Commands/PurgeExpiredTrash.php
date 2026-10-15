<?php

namespace App\Console\Commands;

use App\Helpers\OperationLogHelper;
use App\Models\CollabDocument;
use App\Models\File;
use App\Models\KnowledgeDoc;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Services\TrashService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 回收站到期自动清理。
 *
 * 保留期限由 config('pm.trash_retention_days') 控制（默认 15 天）。
 * 处理范围 = 全系统所有纳入回收站的分类，规则统一。
 *
 * 容器型内容（项目 / 项目模板 / 知识库）在硬删父记录前，
 * 会先逐条彻底删除其子内容（含物理文件与历史快照），
 * 避免数据库外键 CASCADE 硬删记录时留下无人引用的孤儿文件。
 */
class PurgeExpiredTrash extends Command
{
    protected $signature = 'trash:purge-expired
                            {--days= : 覆盖配置中的保留天数}
                            {--dry-run : 只列出将被清理的内容，不实际删除}';

    protected $description = '彻底删除回收站中超过保留期限的内容（含物理文件与历史快照）';

    /**
     * 本次实际清理的条数。
     */
    protected int $purgedTotal = 0;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('pm.trash_retention_days', 15));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('保留天数必须大于 0，已中止。');

            return Command::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $prefix = $dryRun ? '[预演] ' : '';

        $this->info("{$prefix}保留期限：{$days} 天（将清理 {$cutoff->toDateTimeString()} 之前删除的内容）");

        $summary = [];

        foreach (TrashService::TYPES as $type => $config) {
            $before = $this->purgedTotal;
            $this->purgeType($type, $config['model'], $cutoff, $dryRun);
            $count = $this->purgedTotal - $before;

            if ($count > 0) {
                $summary[] = "{$config['label']} {$count} 条";
                $this->line(sprintf('  %s%s：%d 条', $prefix, $config['label'], $count));
            }
        }

        if ($this->purgedTotal === 0) {
            $this->info("{$prefix}没有超过保留期限的内容，无需清理。");

            return Command::SUCCESS;
        }

        $this->info(sprintf('%s共清理 %d 条。', $prefix, $this->purgedTotal));

        if (!$dryRun) {
            OperationLogHelper::log('回收站', '自动清理', sprintf(
                '系统自动彻底删除超过 %d 天的回收站内容，共 %d 条：%s',
                $days,
                $this->purgedTotal,
                implode('、', $summary)
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * 清理某一分类下全部超期记录。
     */
    protected function purgeType(string $type, string $modelClass, Carbon $cutoff, bool $dryRun): void
    {
        $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($type, $dryRun) {
                foreach ($items as $item) {
                    $this->purgeOne($type, $item, $dryRun);
                }
            });
    }

    /**
     * 清理单条记录：先清子内容，再彻底删除自身。
     * 单条失败不影响其余记录。
     */
    protected function purgeOne(string $type, Model $item, bool $dryRun): void
    {
        try {
            if (!$dryRun) {
                $this->purgeChildren($type, $item);
                TrashService::forceDelete($item);
            }

            $this->purgedTotal++;
        } catch (\Throwable $e) {
            $this->error(sprintf('  ✗ %s#%s 清理失败：%s', $type, $item->getKey(), $e->getMessage()));
            report($e);
        }
    }

    /**
     * 容器型内容的子内容清理。
     *
     * 这些子记录会被数据库外键 CASCADE 一并硬删，但 CASCADE 不经过 Eloquent，
     * 不会清理物理文件，因此必须在这里先逐条彻底删除。
     * 使用 withTrashed() 覆盖「已删」与「未删」两种状态，确保不留下孤儿文件。
     */
    protected function purgeChildren(string $type, Model $item): void
    {
        switch ($type) {
            case 'projects':
                $this->purgeProjectChildren($item);
                break;

            case 'project-templates':
                foreach ($item->templateTasks()->get() as $templateTask) {
                    TrashService::forceDelete($templateTask);
                }
                break;

            case 'knowledges':
                // knowledge_docs 无外键约束，不清理会残留孤儿记录
                foreach (KnowledgeDoc::withTrashed()->where('knowledge_id', $item->getKey())->get() as $doc) {
                    TrashService::forceDelete($doc);
                }
                break;
        }
    }

    /**
     * 项目下的协作文档、聊天记录、任务与任务文件。
     */
    protected function purgeProjectChildren(Project $project): void
    {
        foreach (CollabDocument::withTrashed()->where('project_id', $project->id)->get() as $doc) {
            TrashService::forceDelete($doc);
        }

        foreach (Message::withTrashed()->where('project_id', $project->id)->get() as $message) {
            TrashService::forceDelete($message);
        }

        foreach (Task::withTrashed()->where('project_id', $project->id)->get() as $task) {
            foreach (File::withTrashed()->where('task_id', $task->id)->get() as $file) {
                TrashService::forceDelete($file);
            }

            TrashService::forceDelete($task);
        }
    }
}
