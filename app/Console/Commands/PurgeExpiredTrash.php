<?php

namespace App\Console\Commands;

use App\Helpers\OperationLogHelper;
use App\Models\CollabDocument;
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

        // 语音会议里上传的临时演示文件（有效期 7 天）不属于回收站分类，
        // 但同样需要按期清理，否则 voice_demo 目录会持续膨胀。
        $this->purgeExpiredDemoFiles($dryRun);
        if ($this->purgedTotal > 0) {
            $summary[] = "临时演示文件 {$this->purgedTotal} 条";
        }

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
     * 清理单条记录：先清子内容（容器型），再彻底删除自身。
     * 单条失败不影响其余记录。
     *
     * 子内容清理与删除顺序统一由 TrashService::forceDeleteDeep() 保证，
     * 与管理员在回收站手动「彻底删除」共用同一套逻辑，避免两处行为不一致。
     */
    protected function purgeOne(string $type, Model $item, bool $dryRun): void
    {
        try {
            if (!$dryRun) {
                TrashService::forceDeleteDeep($item);
            }

            $this->purgedTotal++;
        } catch (\Throwable $e) {
            $this->error(sprintf('  ✗ %s#%s 清理失败：%s', $type, $item->getKey(), $e->getMessage()));
            report($e);
        }
    }

    /**
     * 清理已过期的临时演示文件。
     *
     * 由 VoiceController::uploadDemoFile() 创建（is_temp_demo=1，有效期 7 天），
     * 属于「到点即失效」的临时内容，因此直接彻底删除（含物理文件），不进回收站。
     */
    protected function purgeExpiredDemoFiles(bool $dryRun): void
    {
        $expired = CollabDocument::withTrashed()
            ->where('is_temp_demo', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->orderBy('id')
            ->get();

        $count = 0;

        foreach ($expired as $doc) {
            try {
                if (!$dryRun) {
                    TrashService::forceDelete($doc);
                }
                $count++;
            } catch (\Throwable $e) {
                $this->error(sprintf('  ✗ 临时演示文件#%s 清理失败：%s', $doc->getKey(), $e->getMessage()));
                report($e);
            }
        }

        if ($count > 0) {
            $this->line(sprintf('  %s临时演示文件：%d 条', $dryRun ? '[预演] ' : '', $count));
        }

        $this->purgedTotal += $count;
    }
}
