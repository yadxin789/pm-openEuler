<?php

namespace App\Http\Controllers;

use App\Models\ProjectTemplate;
use App\Models\TemplateTask;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 项目模板控制器
 * 用于管理项目模板的CRUD操作
 */
class ProjectTemplateController extends Controller
{
    /**
     * 显示项目模板列表
     */
    public function index()
    {
        // 获取所有项目模板，按创建时间倒序排列
        $templates = ProjectTemplate::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('project-template.index', compact('templates'));
    }

    /**
     * 显示创建项目模板页面（任务配置页面）
     */
    public function create()
    {
        // 获取所有任务类型，按排序顺序排列（仅显示 is_required=1 的任务类型）
        $taskTypes = TaskType::with('department')
            ->where('is_required', 1)
            ->orderBy('sort_order')
            ->get();

        // 获取执行人与审核人（仅对应角色的用户，用于选择执行人和审核人）
        $executors = User::with('department', 'role')
            ->where('is_active', true)
            ->whereHas('role', fn($q) => $q->where('code', 'task_executor'))
            ->get();
        $auditors = User::with('department', 'role')
            ->where('is_active', true)
            ->whereHas('role', fn($q) => $q->where('code', 'task_auditor'))
            ->get();

        // 预计算每个部门的默认执行人和审核人
        $defaultExecutors = [];
        $defaultAuditors = [];
        foreach ($taskTypes as $taskType) {
            if ($taskType->department_id) {
                // 默认执行人为部门第一个任务执行人
                $executor = User::where('department_id', $taskType->department_id)
                    ->whereHas('role', fn($q) => $q->where('code', 'task_executor'))
                    ->first();
                if ($executor) {
                    $defaultExecutors[$taskType->department_id] = $executor->id;
                }
                
                // 默认审核人为部门第一个任务审核人
                $auditor = User::where('department_id', $taskType->department_id)
                    ->whereHas('role', fn($q) => $q->where('code', 'task_auditor'))
                    ->first();
                if ($auditor) {
                    $defaultAuditors[$taskType->department_id] = $auditor->id;
                }
            }
        }

        return view('project-template.create', compact('taskTypes', 'executors', 'auditors', 'defaultExecutors', 'defaultAuditors'));
    }

    /**
     * 保存新创建的项目模板
     */
    public function store(Request $request)
    {
        // 验证输入数据
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'selected_depts' => 'required|array',
            'selected_depts.*' => 'required|integer|exists:departments,id',
            'dept_days' => 'nullable|array',
            'dept_days.*' => 'nullable|integer|min:1',
            'tasks' => 'required|array',
            'tasks.*.task_type_id' => 'required|exists:task_types,id',
            'tasks.*.department_id' => 'required|exists:departments,id',
            'tasks.*.executor_id' => 'nullable|exists:users,id',
            'tasks.*.auditor_id' => 'nullable|exists:users,id',
        ]);

        // 创建项目模板
        $template = ProjectTemplate::create([
            'name' => $request->name,
            'description' => $request->description,
            'user_id' => Auth::id(),
        ]);

        // 获取选中的部门
        $selectedDepts = $request->input('selected_depts', []);
        $deptDays = $request->input('dept_days', []);

        // 创建模板任务配置（只保存选中部门且启用的任务）
        foreach ($request->tasks as $index => $taskData) {
            // 只保存选中部门的任务
            if (!in_array($taskData['department_id'], $selectedDepts)) {
                continue;
            }
            
            // 只保存启用的任务（enabled字段存在且为1）
            if (!isset($taskData['enabled']) || $taskData['enabled'] != '1') {
                continue;
            }

            TemplateTask::create([
                'project_template_id' => $template->id,
                'task_type_id' => $taskData['task_type_id'],
                'department_id' => $taskData['department_id'],
                'executor_id' => $taskData['executor_id'] ?? null,
                'auditor_id' => $taskData['auditor_id'] ?? null,
                'days' => $deptDays[$taskData['department_id']] ?? 5,
                'sort_order' => $index,
            ]);
        }

        return redirect()->route('project-template.index')
            ->with('success', '项目模板创建成功');
    }

    /**
     * 显示编辑项目模板页面
     */
    public function edit(ProjectTemplate $projectTemplate)
    {
        // 加载模板的任务配置
        $projectTemplate->load('templateTasks.taskType', 'templateTasks.executor', 'templateTasks.auditor');

        // 获取所有任务类型（仅显示 is_required=1 的任务类型）
        $taskTypes = TaskType::with('department')
            ->where('is_required', 1)
            ->orderBy('sort_order')
            ->get();

        // 获取执行人与审核人（仅对应角色的用户，用于选择执行人和审核人）
        $executors = User::with('department', 'role')
            ->where('is_active', true)
            ->whereHas('role', fn($q) => $q->where('code', 'task_executor'))
            ->get();
        $auditors = User::with('department', 'role')
            ->where('is_active', true)
            ->whereHas('role', fn($q) => $q->where('code', 'task_auditor'))
            ->get();

        // 预计算每个部门的默认执行人和审核人（用于没有配置时的默认值）
        $defaultExecutors = [];
        $defaultAuditors = [];
        foreach ($taskTypes as $taskType) {
            if ($taskType->department_id) {
                // 默认执行人为部门第一个任务执行人
                $executor = User::where('department_id', $taskType->department_id)
                    ->whereHas('role', fn($q) => $q->where('code', 'task_executor'))
                    ->first();
                if ($executor) {
                    $defaultExecutors[$taskType->department_id] = $executor->id;
                }
                
                // 默认审核人为部门第一个任务审核人
                $auditor = User::where('department_id', $taskType->department_id)
                    ->whereHas('role', fn($q) => $q->where('code', 'task_auditor'))
                    ->first();
                if ($auditor) {
                    $defaultAuditors[$taskType->department_id] = $auditor->id;
                }
            }
        }

        return view('project-template.edit', compact('projectTemplate', 'taskTypes', 'executors', 'auditors', 'defaultExecutors', 'defaultAuditors'));
    }

    /**
     * 更新项目模板
     */
    public function update(Request $request, ProjectTemplate $projectTemplate)
    {
        // 验证输入数据
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'selected_depts' => 'required|array',
            'selected_depts.*' => 'required|integer|exists:departments,id',
            'dept_days' => 'nullable|array',
            'dept_days.*' => 'nullable|integer|min:1',
            'tasks' => 'required|array',
            'tasks.*.task_type_id' => 'required|exists:task_types,id',
            'tasks.*.department_id' => 'required|exists:departments,id',
            'tasks.*.executor_id' => 'nullable|exists:users,id',
            'tasks.*.auditor_id' => 'nullable|exists:users,id',
        ]);

        // 更新模板基本信息
        $projectTemplate->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        // 删除旧的模板任务配置
        $projectTemplate->templateTasks()->delete();

        // 获取选中的部门
        $selectedDepts = $request->input('selected_depts', []);
        $deptDays = $request->input('dept_days', []);

        // 创建新的模板任务配置（只保存选中部门且启用的任务）
        foreach ($request->tasks as $index => $taskData) {
            // 只保存选中部门的任务
            if (!in_array($taskData['department_id'], $selectedDepts)) {
                continue;
            }
            
            // 只保存启用的任务（enabled字段存在且为1）
            if (!isset($taskData['enabled']) || $taskData['enabled'] != '1') {
                continue;
            }

            TemplateTask::create([
                'project_template_id' => $projectTemplate->id,
                'task_type_id' => $taskData['task_type_id'],
                'department_id' => $taskData['department_id'],
                'executor_id' => $taskData['executor_id'] ?? null,
                'auditor_id' => $taskData['auditor_id'] ?? null,
                'days' => $deptDays[$taskData['department_id']] ?? 5,
                'sort_order' => $index,
            ]);
        }

        return redirect()->route('project-template.index')
            ->with('success', '项目模板更新成功');
    }

    /**
     * 删除项目模板
     */
    public function destroy(ProjectTemplate $projectTemplate)
    {
        $user = auth()->user();
        abort_unless(
            $user && ($user->isAdmin() || $projectTemplate->user_id === $user->id),
            403,
            '只有模板创建人或管理员可以删除项目模板'
        );

        // 软删除：模板任务配置保留；恢复走个人回收站或管理员统一回收站。
        $projectTemplate->delete();

        return redirect()->route('project-template.index')
            ->with('success', '项目模板已移入回收站，可随时在回收站中恢复');
    }
}