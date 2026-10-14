<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class DashboardService
{
    /**
     * 获取管理员仪表盘数据
     */
    public function getAdminDashboard(): array
    {
        return [
            'userCount' => User::count(),
            'deptCount' => \App\Models\Department::count(),
            'roleCount' => \App\Models\Role::count(),
            'taskCount' => Task::count(),
            'projectCount' => Project::count(),
            'notificationCount' => \App\Models\Notification::count(),
            'projectStatusData' => $this->getAllProjectStatusData(),
        ];
    }

    /**
     * 获取普通用户仪表盘数据
     */
    public function getUserDashboard(User $user): array
    {
        $currentYear = date('Y');

        $userProjectIds = $this->getUserProjectIds($user);
        $userProjects = Project::whereIn('id', $userProjectIds)->get();

        return [
            'currentYear' => $currentYear,
            'projectStats' => $this->getProjectStats($userProjects),
            'monthlyProjectData' => $this->getMonthlyProjectData($userProjectIds, $currentYear),
            'projectStatusData' => $this->getUserProjectStatusData($userProjects),
            'efficiencyStats' => $this->getUserEfficiencyStats($user),
        ];
    }

    /**
     * 获取用户参与的项目ID
     */
    private function getUserProjectIds(User $user)
    {
        $executorTasks = Task::where('executor_id', $user->id)->pluck('project_id');
        $auditorTasks = Task::where('auditor_id', $user->id)->pluck('project_id');
        $creatorProjects = Project::where('creator_id', $user->id)->pluck('id');

        return $executorTasks->merge($auditorTasks)->merge($creatorProjects)->unique();
    }

    /**
     * 获取项目统计数据
     */
    private function getProjectStats($projects): object
    {
        $total = $projects->count();
        $completedOnTime = 0;
        $completedDelayed = 0;
        $inProgress = 0;
        $paused = 0;
        $notStarted = 0;

        foreach ($projects as $project) {
            $statusClass = get_class($project->status);
            if ($statusClass === \App\States\Project\Completed::class) {
                $completedOnTime++;
            } elseif ($statusClass === \App\States\Project\OverdueCompleted::class) {
                $completedDelayed++;
            } elseif ($statusClass === \App\States\Project\InProgress::class) {
                $inProgress++;
            } elseif ($statusClass === \App\States\Project\Overdue::class) {
                $inProgress++;
            } elseif ($statusClass === \App\States\Project\Suspended::class) {
                $paused++;
            } elseif ($statusClass === \App\States\Project\NotStarted::class) {
                $notStarted++;
            }
        }

        return (object) compact('total', 'completedOnTime', 'completedDelayed', 'inProgress', 'paused', 'notStarted');
    }

    /**
     * 获取月度项目数据
     */
    private function getMonthlyProjectData($projectIds, int $year): array
    {
        $monthlyData = array_fill(1, 12, 0);

        $projects = Project::whereIn('id', $projectIds)
            ->whereYear('created_at', $year)
            ->get();

        foreach ($projects as $project) {
            $month = (int) Carbon::parse($project->created_at)->format('m');
            $monthlyData[$month]++;
        }

        return $monthlyData;
    }

    /**
     * 获取用户项目状态分布
     */
    private function getUserProjectStatusData($projects): array
    {
        $statusData = [
            '正常结案' => 0,
            '延期结案' => 0,
            '进行中' => 0,
            '超期' => 0,
            '暂停' => 0,
            '未开始' => 0,
        ];

        foreach ($projects as $project) {
            $statusClass = get_class($project->status);
            if ($statusClass === \App\States\Project\Completed::class) {
                $statusData['正常结案']++;
            } elseif ($statusClass === \App\States\Project\OverdueCompleted::class) {
                $statusData['延期结案']++;
            } elseif ($statusClass === \App\States\Project\InProgress::class) {
                $statusData['进行中']++;
            } elseif ($statusClass === \App\States\Project\Overdue::class) {
                $statusData['超期']++;
            } elseif ($statusClass === \App\States\Project\Suspended::class) {
                $statusData['暂停']++;
            } elseif ($statusClass === \App\States\Project\NotStarted::class) {
                $statusData['未开始']++;
            }
        }

        return $statusData;
    }

    /**
     * 获取用户效率指标
     */
    private function getUserEfficiencyStats(User $user): array
    {
        $tasks = Task::where('executor_id', $user->id)
            ->whereHas('project', function($q) {
                $q->where('status', '!=', \App\States\Project\Suspended::class);
            });
        $taskCount = $tasks->count();
        $completedTasks = (clone $tasks)->where('status', \App\States\Task\Completed::class)->count();
        $pendingTasks = (clone $tasks)->whereIn('status', [
            \App\States\Task\Pending::class,
            \App\States\Task\Rejected::class,
        ])->count();
        $overdueTasks = (clone $tasks)->where('end_date', '<', now())
            ->where('status', '!=', \App\States\Task\Completed::class)->count();
        $completionRate = $taskCount > 0 ? round(($completedTasks / $taskCount) * 100) : 0;
        $totalFiles = \App\Models\File::whereIn('task_id', $tasks->pluck('id'))->count();

        return [
            'task_count' => $taskCount,
            'completed_tasks' => $completedTasks,
            'pending_tasks' => $pendingTasks,
            'overdue_tasks' => $overdueTasks,
            'task_completion_rate' => $completionRate,
            'total_files' => $totalFiles,
        ];
    }

    /**
     * 获取全部项目状态分布（管理员视图）
     */
    private function getAllProjectStatusData(): array
    {
        $projects = Project::all();
        return $this->getUserProjectStatusData($projects);
    }
}
