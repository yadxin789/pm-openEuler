<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(DashboardService $dashboardService)
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            $data = $dashboardService->getAdminDashboard();
            return view('dashboard.admin', $data);
        } elseif ($user->isProjectManager()) {
            return $this->projectManagerDashboard(date('Y'));
        } else {
            $data = $dashboardService->getUserDashboard($user);
            return view('dashboard.index', $data);
        }
    }

    /**
     * 项目总经理仪表盘（查看所有项目数据）
     */
    private function projectManagerDashboard($currentYear)
    {
        $allProjectIds = Project::pluck('id');

        $projectStats = (object) [
            'total' => $allProjectIds->count(),
            'in_progress' => Project::whereIn('status', [
                \App\States\Project\InProgress::class,
                \App\States\Project\Overdue::class,
            ])->count(),
            'completed_on_time' => Project::where('status', \App\States\Project\Completed::class)->count(),
            'completed_delayed' => Project::where('status', \App\States\Project\OverdueCompleted::class)->count(),
            'paused' => Project::where('status', \App\States\Project\Suspended::class)->count(),
        ];

        $monthlyProjectData = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthlyProjectData[$i] = Project::whereMonth('created_at', $i)
                ->whereYear('created_at', $currentYear)
                ->count();
        }

        $projectStatusData = $this->getGlobalProjectStatusData();

        $totalTasks = Task::whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))->count();
        $completedTasks = Task::where('status', \App\States\Task\Completed::class)
            ->whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))
            ->count();
        $overdueTasks = Task::where('end_date', '<', now())
            ->where('status', '!=', \App\States\Task\Completed::class)
            ->whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))
            ->count();

        $efficiencyStats = [
            'task_count' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'pending_tasks' => Task::whereIn('status', [
                \App\States\Task\Pending::class,
                \App\States\Task\Rejected::class,
            ])->whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))->count(),
            'overdue_tasks' => $overdueTasks,
            'task_completion_rate' => $totalTasks > 0
                ? round(($completedTasks / $totalTasks) * 100)
                : 0,
            'total_files' => \App\Models\File::count(),
        ];

        return view('dashboard.index', compact(
            'currentYear',
            'projectStats',
            'monthlyProjectData',
            'projectStatusData',
            'efficiencyStats'
        ));
    }

    /**
     * 全局项目状态分布（饼图数据）
     */
    private function getGlobalProjectStatusData(): array
    {
        return [
            '正常结案' => Project::where('status', \App\States\Project\Completed::class)->count(),
            '延期结案' => Project::where('status', \App\States\Project\OverdueCompleted::class)->count(),
            '进行中' => Project::whereIn('status', [
                \App\States\Project\InProgress::class,
                \App\States\Project\Overdue::class,
            ])->count(),
            '暂停' => Project::where('status', \App\States\Project\Suspended::class)->count(),
        ];
    }
}
