<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Department;
use App\Models\User;
use App\Models\Todo;
use App\Models\ProjectLog;
use App\Models\ProjectTemplate;
use App\States\Project\Suspended;
use App\States\Project\InProgress;
use App\States\Project\Completed;
use App\States\Project\Overdue;
use App\States\Project\OverdueCompleted;
use App\States\Project\NotStarted;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Services\ProjectService;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use Carbon\Carbon;

class ProjectController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = auth()->user();
        
        if ($user->isAdmin() || $user->isProjectManager()) {
            $projectQuery = Project::query();
        } elseif ($user->isProjectCreator()) {
            $projectQuery = Project::where('creator_id', $user->id);
        } else {
            $executorTasks = Task::where('executor_id', $user->id)->pluck('project_id');
            $auditorTasks = Task::where('auditor_id', $user->id)->pluck('project_id');
            $projectIds = $executorTasks->merge($auditorTasks)->unique();
            $projectQuery = Project::whereIn('id', $projectIds);
        }

        // 获取所有项目并计算进度
        $allProjects = (clone $projectQuery)->orderBy('created_at', 'desc')->get();
        foreach ($allProjects as $project) {
            $project->calculateProgress();
        }

        // 按6类分组
        $overdueProjects = $allProjects->filter(function($p) {
            return $p->status->equals(Overdue::class);
        });
        $inProgressProjects = $allProjects->filter(function($p) {
            return $p->status->equals(InProgress::class);
        });
        $completedProjects = $allProjects->filter(function($p) {
            return $p->status->equals(Completed::class);
        });
        $suspendedProjects = $allProjects->filter(function($p) {
            return $p->status->equals(Suspended::class);
        });
        $overdueCompletedProjects = $allProjects->filter(function($p) {
            return $p->status->equals(OverdueCompleted::class);
        });

        // 统计卡片
        $stats = [
            'total' => $allProjects->count(),
            'in_progress' => $inProgressProjects->count(),
            'completed' => $completedProjects->count(),
            'overdue' => $overdueProjects->count(),
            'suspended' => $suspendedProjects->count(),
            'overdue_completed' => $overdueCompletedProjects->count(),
        ];

        return view('project.index', compact(
            'overdueProjects',
            'inProgressProjects',
            'completedProjects',
            'suspendedProjects',
            'overdueCompletedProjects',
            'stats'
        ));
    }

    /**
     * 我的项目列表 - 显示当前用户参与的所有项目，按状态分组
     */
    public function myProjects()
    {
        $user = auth()->user();
        
        // 获取用户参与的项目ID（作为执行者或审核者）
        $executorTasks = Task::where('executor_id', $user->id)->pluck('project_id');
        $auditorTasks = Task::where('auditor_id', $user->id)->pluck('project_id');
        $creatorProjects = Project::where('creator_id', $user->id)->pluck('id');
        
        $projectIds = $executorTasks->merge($auditorTasks)->merge($creatorProjects)->unique();
        
        // 获取所有相关项目
        $allProjects = Project::whereIn('id', $projectIds)
            ->orderBy('created_at', 'desc')
            ->get();
        
        // 自动计算每个项目的进度
        foreach ($allProjects as $project) {
            $project->calculateProgress();
        }
        
        // 按分类分组
        $overdueProjects = $allProjects->filter(function($project) {
            return $project->status->equals(Overdue::class);
        })->sortByDesc('created_at');
        
        $inProgressProjects = $allProjects->filter(function($project) {
            return $project->status->equals(InProgress::class);
        })->sortByDesc('created_at');
        
        $completedProjects = $allProjects->filter(function($project) {
            return $project->status->equals(Completed::class);
        })->sortByDesc('created_at');
        
        $suspendedProjects = $allProjects->filter(function($project) {
            return $project->status->equals(Suspended::class);
        })->sortByDesc('created_at');
        
        $overdueCompletedProjects = $allProjects->filter(function($project) {
            return $project->status->equals(OverdueCompleted::class);
        })->sortByDesc('created_at');

        $notStartedProjects = $allProjects->filter(function($project) {
            return $project->status->equals(NotStarted::class);
        })->sortByDesc('created_at');

        return view('project.my-projects', compact(
            'overdueProjects',
            'inProgressProjects',
            'completedProjects',
            'suspendedProjects',
            'overdueCompletedProjects',
            'notStartedProjects'
        ));
    }

    public function create()
    {
        $taskTypes = \App\Models\TaskType::where('is_required', 1)->orderBy('sort_order')->get();
        $templates = ProjectTemplate::all();
        $departments = \App\Models\Department::projectDepartments()->get();

        return view('project.create', compact('taskTypes', 'templates', 'departments'));
    }

    /**
     * 获取模板配置数据（返回执行人、审核人、部门选择和天数）
     */
    public function getTemplateConfig($templateId)
    {
        $template = ProjectTemplate::with('templateTasks.taskType')->find($templateId);
        
        if (!$template) {
            return response()->json(['error' => '模板不存在'], 404);
        }

        // 模板任务严格按模板保存的排序顺序返回，供创建项目页导入模板时恢复任务顺序
        $templateTasks = $template->templateTasks->sortBy('sort_order')->values();

        // 获取模板中已选择的部门
        $selectedDepts = $templateTasks->pluck('department_id')->unique()->values()->toArray();
        
        // 获取每个部门的天数配置（取同一部门下任意任务的天数）
        $deptDays = [];
        foreach ($selectedDepts as $deptId) {
            $task = $templateTasks->where('department_id', $deptId)->first();
            $deptDays[$deptId] = $task ? $task->days : 5;
        }

        $config = $templateTasks->map(function($task) {
            return [
                'task_type_id' => $task->task_type_id,
                'task_type_name' => $task->taskType ? $task->taskType->name : '',
                'department_id' => $task->department_id,
                'executor_id' => $task->executor_id,
                'auditor_id' => $task->auditor_id,
                'days' => $task->days,
                'sort_order' => $task->sort_order,
            ];
        });

        return response()->json([
            'selected_depts' => $selectedDepts,
            'dept_days' => $deptDays,
            'tasks' => $config,
        ]);
    }

    public function store(StoreProjectRequest $request, ProjectService $projectService)
    {
        try {
            // 如果没有提供项目编码，自动生成
            $code = $projectService->generateProjectCode(
                $request->product_name,
                $request->code
            );

            $selectedDeptIds = $request->selected_depts;
            $deptDays = $request->dept_days;

            // 按部门分组任务（只处理选中部门且启用的任务）
            $tasksByDept = [];
            foreach ($request->tasks as $taskData) {
                $deptId = $taskData['department_id'];
                if (!in_array($deptId, $selectedDeptIds)) {
                    continue;
                }
                // 只处理启用的任务（enabled字段存在且为1）
                if (!isset($taskData['enabled']) || $taskData['enabled'] != '1') {
                    continue;
                }
                if (!isset($tasksByDept[$deptId])) {
                    $tasksByDept[$deptId] = [];
                }
                $tasksByDept[$deptId][] = $taskData;
            }

            $data = $request->validated();
            $data['code'] = $code;

            $projectService->createProject($data, $tasksByDept, $selectedDeptIds, $deptDays, auth()->id());

            return redirect()->route('project.index')->with('success', '项目创建成功');
        } catch (\Exception $e) {
            \Log::error('项目创建失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'user_id' => auth()->id()]);
            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => '项目创建不成功。错误：' . $e->getMessage()]);
        }
    }

    public function show(Project $project)
    {
        Gate::authorize('view', $project);

        $tasks = $project->tasks()
            ->with('executor', 'auditor', 'department', 'files.uploader')
            ->orderBy('start_date', 'asc')
            ->get();
        $canViewAllTasks = auth()->id() == $project->creator_id
            || auth()->user()->isProjectManager()
            || auth()->user()->isAdmin();
        // 普通任务执行人/审核人仅展示自己在该项目中负责执行或审核的任务。
        $visibleTasks = $canViewAllTasks
            ? $tasks
            : $tasks->filter(function ($task) {
                return $task->executor_id === auth()->id()
                    || $task->auditor_id === auth()->id();
            })->values();
        $participants = $project->participants();
        $participatingDepts = $project->participatingDepartments();
        
        // 仅计算实际参与部门的进度
        $departmentProgress = [];
        foreach ($participatingDepts as $dept) {
            $departmentProgress[$dept->id] = $project->getDepartmentProgress($dept->id);
        }
        
        // 计算项目统计信息
        $stats = [
            'total_tasks' => $tasks->count(),
            'completed_tasks' => $tasks->filter(fn($t) => $t->status->equals(\App\States\Task\Completed::class))->count(),
            'pending_tasks' => $tasks->filter(fn($t) => $t->status->equals(\App\States\Task\Pending::class))->count(),
            'for_audit_tasks' => $tasks->filter(fn($t) => $t->status->equals(\App\States\Task\ForAudit::class))->count(),
            'rejected_tasks' => $tasks->filter(fn($t) => $t->status->equals(\App\States\Task\Rejected::class))->count(),
            'overdue_tasks' => $tasks->filter(fn($t) => $t->status->equals(\App\States\Task\Overdue::class))->count(),
        ];
        
        return view('project.show', compact('project', 'tasks', 'visibleTasks', 'participants', 'departmentProgress', 'stats', 'participatingDepts', 'canViewAllTasks'));
    }

    public function edit(Project $project)
    {
        Gate::authorize('update', $project);
        return view('project.edit', compact('project'));
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());
        return redirect()->route('project.show', $project)->with('success', '项目更新成功');
    }

    public function delete(Project $project)
    {
        Gate::authorize('delete', $project);
        $project->delete();
        return redirect()->route('project.index')->with('success', '项目已移入回收站，可随时在回收站中恢复');
    }

    /**
     * 暂停项目
     */
    public function pause(Request $request, Project $project, ProjectService $projectService)
    {
        Gate::authorize('pause', $project); // Policy + 模型统一判断，已确保 InProgress/Overdue 状态

        $request->validate([
            'paused_reason' => 'required|string|max:500',
        ]);

        $projectService->pauseProject($project, $request->paused_reason, auth()->id());

        return redirect()->back()->with('success', '项目已暂停');
    }

    /**
     * 恢复/重启项目
     */
    public function resume(Project $project, ProjectService $projectService)
    {
        Gate::authorize('resume', $project); // 修复：原先是错误的 'pause'，现在用正确的 'resume'，Policy 保证必须是 Suspended 状态

        $pausedDays = $projectService->resumeProject($project, auth()->id());

        return redirect()->back()->with('success', "项目已恢复，暂停了 {$pausedDays} 天");
    }

    public function getDesigners($departmentId)
    {
        $designers = User::where('department_id', $departmentId)
            ->whereHas('role', fn($q) => $q->where('code', 'task_executor'))
            ->get(['id', 'name']);
        return response()->json($designers);
    }

    public function getManagers($departmentId)
    {
        $managers = User::where('department_id', $departmentId)
            ->whereHas('role', fn($q) => $q->where('code', 'task_auditor'))
            ->get(['id', 'name']);
        return response()->json($managers);
    }

    /**
     * 显示变更项目页面
     */
    public function change(Project $project)
    {
        Gate::authorize('change', $project);

        $participatingDepts = $project->participatingDepartments();

        return view('project.change', compact('project', 'participatingDepts'));
    }

    /**
     * 更新变更信息
     * 变更日期和任务状态参数后，重新计算所有任务的日期和状态
     */
    public function updateChange(Request $request, Project $project, ProjectService $projectService)
    {
        Gate::authorize('change', $project);

        $request->validate([
            'name' => 'required|string|max:255',
            'product_name' => 'required|string|max:255',
            'product_model' => 'required|string|max:255',
            'start_date' => 'required|date',
            'change_reason' => 'required|string',
            'dept_days' => 'required|array',
            'dept_days.*' => 'required|integer|min:1',
        ]);

        // 记录旧值
        $oldData = [
            'name' => $project->name,
            'product_name' => $project->product_name,
            'product_model' => $project->product_model,
            'start_date' => $project->start_date?->toDateString(),
        ];

        // 更新项目基本信息
        $project->update([
            'name' => $request->name,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'start_date' => $request->start_date,
        ]);

        // 重新计算所有任务的日期和状态
        $projectService->recalculateDates($project, $request->start_date, $request->dept_days);

        // 记录变更日志
        $changes = [];
        foreach ($oldData as $field => $oldValue) {
            $newValue = $project->$field;
            if (is_object($newValue)) {
                $newValue = $newValue->toDateString();
            }
            if ($oldValue != $newValue) {
                $changes[] = "{$field}: {$oldValue} → {$newValue}";
            }
        }

        ProjectLog::create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'action' => '项目变更',
            'content' => "变更原因：{$request->change_reason}\n变更后已重新计算所有任务日期\n" . implode("\n", $changes),
        ]);

        return redirect()->route('project.show', $project)->with('success', '项目变更成功，所有任务日期已重新计算');
    }

    /**
     * 显示甘特图
     */
    public function gantt(Project $project)
    {
        Gate::authorize('view', $project);

        $tasks = $project->tasks()->with('executor', 'department')->orderBy('start_date')->get();
        $participatingDepts = $project->participatingDepartments();
        
        return view('project.gantt', compact('project', 'tasks', 'participatingDepts'));
    }

    public function logs(Project $project)
    {
        Gate::authorize('view', $project);

        $logs = $project->logs()->with('user')->orderBy('created_at', 'desc')->get();
        return view('project.logs', compact('project', 'logs'));
    }

    /**
     * 我的逾期项目列表（分页+过滤）
     */
    public function myOverdueProjects(Request $request)
    {
        return $this->myProjectsByStatus($request, \App\States\Project\Overdue::class, '逾期');
    }

    /**
     * 我的进行中项目列表（分页+过滤）
     */
    public function myInProgressProjects(Request $request)
    {
        return $this->myProjectsByStatus($request, \App\States\Project\InProgress::class, '进行中');
    }

    /**
     * 我的已完成项目列表（分页+过滤）
     */
    public function myCompletedProjects(Request $request)
    {
        return $this->myProjectsByStatus($request, \App\States\Project\Completed::class, '已完成');
    }

    /**
     * 我的暂停项目列表（分页+过滤）
     */
    public function mySuspendedProjects(Request $request)
    {
        return $this->myProjectsByStatus($request, \App\States\Project\Suspended::class, '暂停');
    }

    /**
     * 按状态获取我的项目（分页+过滤）
     */
    private function myProjectsByStatus(Request $request, string $statusClass, string $statusLabel)
    {
        $user = auth()->user();
        
        // 获取用户参与的项目ID
        $executorTasks = Task::where('executor_id', $user->id)->pluck('project_id');
        $auditorTasks = Task::where('auditor_id', $user->id)->pluck('project_id');
        $creatorProjects = Project::where('creator_id', $user->id)->pluck('id');
        
        $projectIds = $executorTasks->merge($auditorTasks)->merge($creatorProjects)->unique();
        
        // 获取所有相关项目
        $allProjects = Project::whereIn('id', $projectIds)
            ->orderBy('created_at', 'desc')
            ->get();
        
        foreach ($allProjects as $project) {
            $project->calculateProgress();
        }
        
        // 过滤指定状态
        $filtered = $allProjects->filter(function($project) use ($statusClass) {
            return get_class($project->status) === $statusClass;
        })->sortByDesc('created_at');
        
        // 应用筛选条件
        if ($request->filled('name')) {
            $filtered = $filtered->filter(fn($p) => stripos($p->name, $request->name) !== false);
        }
        if ($request->filled('date_from')) {
            $filtered = $filtered->filter(fn($p) => $p->start_date && $p->start_date->gte($request->date_from));
        }
        if ($request->filled('date_to')) {
            $filtered = $filtered->filter(fn($p) => $p->start_date && $p->start_date->lte($request->date_to));
        }
        
        // 手动分页
        $page = $request->get('page', 1);
        $perPage = 15;
        $total = $filtered->count();
        $projects = new \Illuminate\Pagination\LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        
        return view('project.my-projects-status', compact('projects', 'statusLabel'));
    }

}