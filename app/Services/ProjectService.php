<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\Todo;
use App\Models\ProjectLog;
use App\Models\Department;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectService
{
    /**
     * 创建项目及其关联任务
     */
    public function createProject(array $data, array $tasksByDept, array $selectedDeptIds, array $deptDays, int $creatorId): Project
    {
        return DB::transaction(function () use ($data, $tasksByDept, $selectedDeptIds, $deptDays, $creatorId) {
            $deptIds = Department::projectDepartments()
                ->whereIn('id', $selectedDeptIds)
                ->pluck('id')
                ->toArray();

            $deptStartDate = Carbon::parse($data['start_date']);
            $projectEndDate = $data['start_date'];

            foreach ($deptIds as $deptId) {
                $days = (int)($deptDays[$deptId] ?? 1);
                $deptEndDate = $deptStartDate->copy()->addDays($days - 1);
                $projectEndDate = $deptEndDate->toDateString();
                $deptStartDate = $deptEndDate->copy()->addDay();
            }

            $project = Project::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'version' => $data['version'],
                'product_name' => $data['product_name'],
                'product_model' => $data['product_model'],
                'creator_id' => $creatorId,
                'start_date' => $data['start_date'],
                'end_date' => $projectEndDate,
                'progress' => 0,
            ]);

            $deptStartDate = Carbon::parse($data['start_date']);

            foreach ($deptIds as $deptId) {
                $days = (int)($deptDays[$deptId] ?? 1);
                $deptEndDate = $deptStartDate->copy()->addDays($days - 1);

                foreach (($tasksByDept[$deptId] ?? []) as $taskData) {
                    $taskType = \App\Models\TaskType::find($taskData['task_type_id']);
                    $taskName = $taskType ? $taskType->name : '未命名任务';

                    $task = Task::create([
                        'project_id' => $project->id,
                        'department_id' => $taskData['department_id'],
                        'task_type_id' => $taskData['task_type_id'],
                        'name' => $taskName,
                        'executor_id' => $taskData['executor_id'],
                        'auditor_id' => $taskData['auditor_id'],
                        'start_date' => $deptStartDate->copy(),
                        'end_date' => $deptEndDate->copy(),
                    ]);

                    Todo::create([
                        'user_id' => $taskData['executor_id'],
                        'type' => 'task',
                        'title' => "【{$project->name}】{$taskName}",
                        'related_type' => 'task',
                        'related_id' => $task->id,
                    ]);
                }

                $deptStartDate = $deptEndDate->copy()->addDay();
            }

            // 通知参与者
            $participants = $project->participants();
            foreach ($participants as $participant) {
                $participant->notify(new SystemNotification(
                    title: '您已加入新项目',
                    content: "您已被添加到项目「{$project->name}」中",
                    relatedType: 'project',
                    relatedId: $project->id,
                    senderId: $creatorId,
                    type: 'project',
                ));
            }

            ProjectLog::create([
                'project_id' => $project->id,
                'user_id' => $creatorId,
                'action' => 'created',
                'content' => '创建项目',
            ]);

            return $project;
        });
    }

    /**
     * 暂停项目
     */
    public function pauseProject(Project $project, string $reason, int $userId): void
    {
        DB::transaction(function () use ($project, $reason, $userId) {
            $now = now();

            $project->status->transitionTo(\App\States\Project\Suspended::class);
            $project->paused_reason = $reason;
            $project->paused_at = $now;
            $project->resumed_at = null;
            $project->save();

            $project->tasks()->whereNotIn('status', [
                \App\States\Task\Completed::class,
            ])->update(['paused_at' => $now]);

            $participants = $project->participants();
            foreach ($participants as $participant) {
                $participant->notify(new SystemNotification(
                    title: '项目已暂停',
                    content: "项目「{$project->name}」已暂停。原因：{$reason}",
                    relatedType: 'project',
                    relatedId: $project->id,
                    senderId: $userId,
                    type: 'project_paused',
                ));
            }

            ProjectLog::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'action' => 'paused',
                'content' => "项目已暂停。原因：{$reason}",
            ]);
        });
    }

    /**
     * 恢复项目（重启已暂停项目）
     * 1. 新项目起始日期 = 恢复当天服务器日期
     * 2. 部门全部任务已完成：保持原日期不参与计算
     * 3. 部门未全部完成：按创建时分配给该部门的天数，重新分配给该部门所有任务（无论任务状态），纳入计算
     * 4. 根据新起始日期和各部门有效天数，依次串行计算项目截止日期
     */
    public function resumeProject(Project $project, int $userId): int
    {
        return DB::transaction(function () use ($project, $userId) {
            $now = now();
            $pausedDays = 0;

            if ($project->paused_at) {
                $pausedDays = (int) Carbon::parse($project->paused_at)->diffInDays($now);
            }
            $project->total_paused_days += $pausedDays;

            $project->status->transitionTo(\App\States\Project\InProgress::class);
            $project->resumed_at = $now;
            $project->start_date = $now->copy();
            $project->save();

            // 按部门和原始开始日期排序（与创建项目顺序一致）
            $allTasks = $project->tasks()
                ->orderBy('department_id')
                ->orderBy('start_date')
                ->get();

            // 按部门分组
            $tasksByDept = [];
            foreach ($allTasks as $task) {
                $tasksByDept[$task->department_id][] = $task;
            }

            // 保持原始部门顺序
            $deptIds = $project->tasks()
                ->select('department_id')
                ->distinct()
                ->orderBy('department_id')
                ->pluck('department_id')
                ->toArray();

            // 以重启日期作为新的起始日期，按部门串行计算
            $currentDate = $now->copy();
            $projectEndDate = $now->copy();

            foreach ($deptIds as $deptId) {
                $deptTasks = $tasksByDept[$deptId] ?? [];
                if (empty($deptTasks)) {
                    continue;
                }

                // 判断该部门是否所有任务都已完成（完成含 Completed 与 OverdueCompleted）
                $allCompleted = true;
                foreach ($deptTasks as $task) {
                    if (!$task->status->equals(\App\States\Task\Completed::class)
                        && !$task->status->equals(\App\States\Task\OverdueCompleted::class)) {
                        $allCompleted = false;
                        break;
                    }
                }

                if ($allCompleted) {
                    // 全部完成：保持原日期，不参与排期计算
                    foreach ($deptTasks as $task) {
                        $task->paused_at = null;
                        $task->save();
                    }
                    continue;
                }

                // 部门未全部完成：根据原始起止日反推该部门分配的天数
                $firstTask = reset($deptTasks);
                $deptOrigStart = Carbon::parse($firstTask->start_date);
                $deptOrigEnd = $deptOrigStart->copy();
                foreach ($deptTasks as $task) {
                    $tEnd = Carbon::parse($task->end_date);
                    if ($tEnd->gt($deptOrigEnd)) {
                        $deptOrigEnd = $tEnd;
                    }
                }
                $deptDays = (int) $deptOrigStart->diffInDays($deptOrigEnd) + 1;

                // 计算该部门新的起止日期（同一部门所有任务共享）
                $newDeptStart = $currentDate->copy();
                $newDeptEnd = $newDeptStart->copy()->addDays($deptDays - 1);

                // 对部门内所有任务（无论是否已完成）分配相同的新日期
                foreach ($deptTasks as $task) {
                    $task->start_date = $newDeptStart;
                    $task->end_date = $newDeptEnd;
                    $task->paused_at = null;

                    // 任务状态调整：Overdue 且没有已上传文件 → 改为 InProgress
                    if ($task->status->equals(\App\States\Task\Overdue::class)) {
                        $hasUploadedFile = $task->files()->whereIn('status', ['approved', 'pending'])->exists();
                        if (!$hasUploadedFile) {
                            $task->status->transitionTo(\App\States\Task\InProgress::class);
                        }
                    }

                    // 状态调整：Pending/InProgress 因新截止日过期 → 改为 Overdue
                    if ($task->status->equals(\App\States\Task\Pending::class)
                        || $task->status->equals(\App\States\Task\InProgress::class)) {
                        if ($newDeptEnd->lt(now())) {
                            $task->is_overdue = true;
                            $task->status->transitionTo(\App\States\Task\Overdue::class);
                        } else {
                            $task->is_overdue = false;
                        }
                    }

                    $task->save();
                }

                // 推进指针，累计项目截止日期
                $currentDate = $newDeptEnd->copy()->addDay();
                $projectEndDate = $newDeptEnd->copy();
            }

            // 项目截止日期 = 所有任务中的最大截止日（含保持原日期的已完成部门）
            $maxTaskEndDate = $project->tasks()->max('end_date');
            $project->end_date = $maxTaskEndDate ? Carbon::parse($maxTaskEndDate) : $now->copy();
            $project->save();

            $participants = $project->participants();
            foreach ($participants as $participant) {
                $participant->notify(new SystemNotification(
                    title: '项目已恢复',
                    content: "项目「{$project->name}」已恢复。暂停了 {$pausedDays} 天，以恢复日期为新的起始日期按部门重新计算了任务排期。",
                    relatedType: 'project',
                    relatedId: $project->id,
                    senderId: $userId,
                    type: 'project_resumed',
                ));
            }

            ProjectLog::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'action' => 'resumed',
                'content' => "项目已恢复。暂停了 {$pausedDays} 天，以恢复日期为新的起始日期按部门重新计算了任务排期。",
            ]);

            return $pausedDays;
        });
    }

    /**
     * 获取用户参与的项目（按状态分组）
     */
    public function getUserProjectsGrouped($user): array
    {
        $executorTasks = Task::where('executor_id', $user->id)->pluck('project_id');
        $auditorTasks = Task::where('auditor_id', $user->id)->pluck('project_id');
        $creatorProjects = Project::where('creator_id', $user->id)->pluck('id');

        $projectIds = $executorTasks->merge($auditorTasks)->merge($creatorProjects)->unique();

        $allProjects = Project::whereIn('id', $projectIds)
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($allProjects as $project) {
            $project->calculateProgress();
        }

        $overdueCompletedTasks = $allProjects->filter(fn($p) => $p->status->equals(\App\States\Project\OverdueCompleted::class))->values();

        return [
            'overdue' => $allProjects->filter(fn($p) => $p->status->equals(\App\States\Project\Overdue::class))->values(),
            'in_progress' => $allProjects->filter(fn($p) => $p->status->equals(\App\States\Project\InProgress::class))->values(),
            'completed' => $allProjects->filter(fn($p) => $p->status->equals(\App\States\Project\Completed::class))->values(),
            'suspended' => $allProjects->filter(fn($p) => $p->status->equals(\App\States\Project\Suspended::class))->values(),
            'overdue_completed' => $overdueCompletedTasks,
        ];
    }

    /**
     * 变更项目日期和参数后，重新计算所有任务的日期和状态
     * 用于项目创建人修改项目日期和任务状态相关参数时
     */
    public function recalculateDates(Project $project, string $newStartDate, array $deptDays): void
    {
        DB::transaction(function () use ($project, $newStartDate, $deptDays) {
            // 按部门和原始顺序获取所有任务
            $allTasks = $project->tasks()
                ->orderBy('department_id')
                ->orderBy('start_date')
                ->get();

            $deptStartDate = Carbon::parse($newStartDate);
            $projectEndDate = $newStartDate;

            // 按部门分组任务
            $tasksByDept = [];
            foreach ($allTasks as $task) {
                $tasksByDept[$task->department_id][] = $task;
            }

            // 按部门顺序串行计算任务日期
            $deptIds = $project->tasks()
                ->select('department_id')
                ->distinct()
                ->orderBy('department_id')
                ->pluck('department_id')
                ->toArray();

            $currentDate = $deptStartDate->copy();

            foreach ($deptIds as $deptId) {
                $days = (int)($deptDays[$deptId] ?? 1);
                $deptEndDate = $currentDate->copy()->addDays($days - 1);

                if (isset($tasksByDept[$deptId])) {
                    foreach ($tasksByDept[$deptId] as $task) {
                        $oldEndDate = Carbon::parse($task->end_date);

                        // 更新任务的起始和截止日期
                        $task->start_date = $currentDate->copy();
                        $task->end_date = $deptEndDate->copy();

                        // 更新任务状态：如果旧截止日期已过期，且新截止日期在未来，恢复为进行中
                        if ($task->status->equals(\App\States\Task\Overdue::class)) {
                            if ($deptEndDate->gt(now())) {
                                $task->status->transitionTo(\App\States\Task\InProgress::class);
                            }
                        } elseif ($task->status->equals(\App\States\Task\Pending::class)
                            || $task->status->equals(\App\States\Task\InProgress::class)) {
                            // 如果新截止日期已过期，改为已逾期
                            if ($deptEndDate->lt(now())) {
                                $task->status->transitionTo(\App\States\Task\Overdue::class);
                            }
                        }

                        $task->is_overdue = false;
                        $task->save();
                    }
                }

                $projectEndDate = $deptEndDate->toDateString();
                $currentDate = $deptEndDate->copy()->addDay();
            }

            // 更新项目日期
            $project->start_date = $newStartDate;
            $project->end_date = $projectEndDate;
            $project->save();

            // 更新项目进度
            $project->calculateProgress();
        });
    }

    /**
     * 生成项目编码前缀
     */
    public function generateCodePrefix(string $productName): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}]/u', '', $productName);

        if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $cleaned)) {
            preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $cleaned, $matches);
            $chars = $matches[0];
            $prefix = '';
            for ($i = 0; $i < min(3, count($chars)); $i++) {
                $prefix .= $chars[$i];
            }
            return strtoupper($prefix);
        }

        $words = preg_split('/[\s_-]+/', $cleaned);
        $prefix = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $prefix .= strtoupper(substr($word, 0, 1));
            }
        }

        return empty($prefix) || strlen($prefix) > 5 ? 'PRJ' : $prefix;
    }

    /**
     * 生成唯一项目编码
     */
    public function generateProjectCode(string $productName, ?string $requestCode = null): string
    {
        if (!empty($requestCode)) {
            return $requestCode;
        }

        $prefix = $this->generateCodePrefix($productName);
        $code = $prefix . date('YmdHis');

        while (Project::where('code', $code)->exists()) {
            $code = $prefix . date('YmdHis') . rand(10, 99);
        }

        return $code;
    }
}