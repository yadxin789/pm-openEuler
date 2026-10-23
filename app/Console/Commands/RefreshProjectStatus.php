<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Models\Task;
// 注意：状态类的实际名称是 Completed / InProgress，
// 原先的 TaskCompleted / ProjectInProgress / ProjectCompleted 并不存在，
// 导致 ::class 解析成不存在的类名、查询条件永远匹配不到（本命令长期未真正生效）。
use App\States\Task\Completed as TaskCompleted;
use App\States\Project\InProgress as ProjectInProgress;
use App\States\Project\Completed as ProjectCompleted;
use App\States\Project\Overdue as ProjectOverdue;
use App\States\Project\Suspended as ProjectSuspended;
use Carbon\Carbon;

class RefreshProjectStatus extends Command
{
    protected $signature = 'projects:refresh-status';
    protected $description = '自动刷新项目状态、任务状态、进度和统计数据';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('开始刷新项目状态和进度...');
        $updatedCount = 0;
        
        // 1. 分块更新所有项目的进度（排除已暂停项目）
        Project::where('status', '!=', ProjectSuspended::class)
            ->chunk(50, function ($projects) use (&$updatedCount) {
            foreach ($projects as $project) {
                $oldProgress = $project->progress;
                $newProgress = $project->calculateProgress();
                
                if ($oldProgress != $newProgress) {
                    $updatedCount++;
                    $this->line("项目 [{$project->name}] 进度更新: {$oldProgress}% -> {$newProgress}%");
                }
                
                $this->updateProjectStatus($project);
            }
        });
        
        // 2. 检查并更新过期任务状态
        $this->updateOverdueTasks();
        
        // 3. 检查并更新过期项目状态
        $this->updateOverdueProjects();
        
        $this->info("刷新完成！共更新 {$updatedCount} 个项目的进度。");
        
        return Command::SUCCESS;
    }
    
    /**
     * 根据任务完成情况自动更新项目状态
     *
     * 注意：只处理「进行中」项目。
     * 已完成 / 逾期完成 / 逾期 / 已暂停 项目的状态由各自业务流程（calculateProgress、暂停恢复、逾期检查）负责，
     * 这里再强行改状态会把逾期项目错误地改回进行中或已完成。
     */
    private function updateProjectStatus(Project $project)
    {
        if (!$project->status->equals(ProjectInProgress::class)) {
            return;
        }

        $totalTasks = $project->tasks()->count();
        $completedTasks = $project->tasks()->whereIn('status', [
            TaskCompleted::class,
            \App\States\Task\OverdueCompleted::class,
        ])->count();

        if ($totalTasks == 0) {
            return; // 没有任务，不更新状态
        }

        $completionRate = ($completedTasks / $totalTasks) * 100;

        // 如果所有任务都完成，将项目状态改为已完成
        if ($completionRate == 100) {
            try {
                $project->status->transitionTo(ProjectCompleted::class);
                $this->line("✓ 项目 [{$project->name}] 状态更新为: 已完成");
            } catch (\Exception $e) {
                $this->error("✗ 项目 [{$project->name}] 状态更新失败: " . $e->getMessage());
            }
        }
    }
    
    /**
     * 更新过期任务的状态
     */
    private function updateOverdueTasks()
    {
        $now = Carbon::now();
        
        // 查找已过截止日期但未完成、未标记逾期、未进入终态的任务（排除已暂停项目）
        $overdueTasks = Task::where('end_date', '<', $now)
            ->whereNotIn('status', [
                TaskCompleted::class,
                \App\States\Task\OverdueCompleted::class,
                \App\States\Task\Overdue::class,
            ])
            ->whereHas('project', function($q) {
                $q->where('status', '!=', ProjectSuspended::class);
            })
            ->get();
        
        $overdueCount = $overdueTasks->count();
        
        if ($overdueCount > 0) {
            $updatedCount = 0;
            foreach ($overdueTasks as $task) {
                // 已提交文件、等待审核的任务不在这里改判为逾期（审核流程照常进行）
                if ($task->status->equals(\App\States\Task\ForAudit::class) && $task->hasPendingFile()) {
                    continue;
                }

                try {
                    $oldStatus = $task->status->label();
                    $task->is_overdue = true;
                    $task->status->transitionTo(\App\States\Task\Overdue::class);
                    $task->save();
                    $updatedCount++;
                    $this->warn("  任务 [{$task->name}] 状态已更新: {$oldStatus} → 已逾期");
                } catch (\Exception $e) {
                    $this->error("  任务 [{$task->name}] 状态更新失败: " . $e->getMessage());
                }
            }
            $this->warn("共更新 {$updatedCount}/{$overdueCount} 个逾期任务的状态");
        }
    }
    
    /**
     * 更新过期项目的状态
     */
    private function updateOverdueProjects()
    {
        $now = Carbon::now();
        
        // 查找已过截止日期但未完成、未标记逾期的项目（排除已暂停）
        $overdueProjects = Project::where('end_date', '<', $now->toDateString())
            ->whereNotIn('status', [ProjectCompleted::class, ProjectOverdue::class, ProjectSuspended::class])
            ->where('progress', '<', 100)
            ->get();
        
        $overdueCount = $overdueProjects->count();
        
        if ($overdueCount > 0) {
            $updatedCount = 0;
            foreach ($overdueProjects as $project) {
                try {
                    $oldStatus = $project->status->label();
                    $project->status->transitionTo(ProjectOverdue::class);
                    $project->save();
                    $updatedCount++;
                    $this->warn("  项目 [{$project->name}] 状态已更新: {$oldStatus} → 已逾期");
                } catch (\Exception $e) {
                    $this->error("  项目 [{$project->name}] 状态更新失败: " . $e->getMessage());
                }
            }
            $this->warn("共更新 {$updatedCount}/{$overdueCount} 个逾期项目的状态");
        }
    }
}
