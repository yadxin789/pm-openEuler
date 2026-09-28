<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\States\Project\Completed;
use App\States\Project\InProgress;
use App\States\Project\Overdue;
use App\States\Project\OverdueCompleted;
use App\States\Project\Suspended;
use App\States\Task as TaskStates;

class WelcomeController extends Controller
{
    public function index()
    {
        $currentYear = date('Y');
        
        // 项目统计（使用 Spatie ModelStates 状态类名）
        $projectStats = (object) [
            'total' => Project::count(),
            'completed_on_time' => Project::where('status', Completed::class)->count(),
            'completed_delayed' => Project::where('status', OverdueCompleted::class)->count(),
            'in_progress' => Project::whereIn('status', [InProgress::class, Overdue::class])->count(),
        ];

        // 月度项目数据
        $monthlyProjectData = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthlyProjectData[$i] = Project::whereMonth('created_at', $i)
                ->whereYear('created_at', $currentYear)
                ->count();
        }

        // 项目状态分布（饼图数据）
        $projectStatusData = [
            '正常结案' => Project::where('status', Completed::class)->count(),
            '延期结案' => Project::where('status', OverdueCompleted::class)->count(),
            '进行中' => Project::whereIn('status', [InProgress::class, Overdue::class])->count(),
            '暂停' => Project::where('status', Suspended::class)->count(),
        ];

        // 效率指标（使用 Spatie ModelStates，排除暂停项目中的任务）
        $taskCount = Task::whereHas('project', fn($q) => $q->where('status', '!=', Suspended::class))->count();
        $completedTaskCount = Task::where('status', TaskStates\Completed::class)
            ->whereHas('project', fn($q) => $q->where('status', '!=', Suspended::class))
            ->count();
        $overdueTaskCount = Task::where('end_date', '<', now())
            ->where('status', '!=', TaskStates\Completed::class)
            ->whereHas('project', fn($q) => $q->where('status', '!=', Suspended::class))
            ->count();
        
        $efficiencyStats = [
            'task_count' => $taskCount,
            'completed_tasks' => $completedTaskCount,
            'overdue_tasks' => $overdueTaskCount,
            'task_completion_rate' => $taskCount > 0 ? round(($completedTaskCount / $taskCount) * 100) : 0,
            'on_time_completion_rate' => $taskCount > 0 ? round((Task::where('status', TaskStates\Completed::class)->where('end_date', '>=', now())->whereHas('project', fn($q) => $q->where('status', '!=', Suspended::class))->count() / $taskCount) * 100) : 0,
            'overdue_rate' => $taskCount > 0 ? round(($overdueTaskCount / $taskCount) * 100) : 0,
            'project_count' => Project::count(),
        ];

        return view('welcome', compact(
            'currentYear',
            'projectStats',
            'monthlyProjectData',
            'projectStatusData',
            'efficiencyStats'
        ));
    }
}