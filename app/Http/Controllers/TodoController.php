<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\ExtensionApplication;
use App\Models\Task;
use App\Services\ExtensionService;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    protected ExtensionService $extensionService;

    public function __construct(ExtensionService $extensionService)
    {
        $this->middleware('auth');
        $this->extensionService = $extensionService;
    }

    /**
     * 禁止对暂停状态项目的任务执行操作
     */
    protected function blockIfProjectInactive(ExtensionApplication $application): void
    {
        $application->loadMissing('task.project');
        $project = $application->task?->project;
        if (!$project) return;

        $projectStatus = get_class($project->status);
        if ($projectStatus === \App\States\Project\Suspended::class) {
            abort(403, '项目已暂停，无法执行任务操作。请等待项目恢复后再试。');
        }
    }

    public function index()
    {
        $userId = auth()->id();
        
        // 获取所有待办，分页加载
        $todosQuery = Todo::where('user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc');
        
        $todos = $todosQuery->paginate(15);
        
        // 批量预加载关联数据，消除 N+1 问题
        $taskIds = $todos->where('related_type', 'task')->pluck('related_id')->unique()->toArray();
        $extensionIds = $todos->where('related_type', 'extension_application')->pluck('related_id')->unique()->toArray();
        
        $tasks = collect();
        $extensions = collect();
        
        if (!empty($taskIds)) {
            $tasks = Task::whereIn('id', $taskIds)
                ->whereHas('project', function($q) {
                    $q->whereNotIn('status', [
                        \App\States\Project\Suspended::class,
                    ]);
                })
                ->with(['project', 'taskType'])
                ->get()
                ->keyBy('id');
        }
        
        if (!empty($extensionIds)) {
            $extensions = ExtensionApplication::whereIn('id', $extensionIds)
                ->whereHas('task.project', function($q) {
                    $q->whereNotIn('status', [
                        \App\States\Project\Suspended::class,
                    ]);
                })
                ->with('task.project')
                ->get()
                ->keyBy('id');
        }
        
        // 构建待办列表数据
        $todoTasks = [];
        $extensionTodos = [];
        $today = now()->toDateString();
        
        foreach ($todos as $todo) {
            if ($todo->related_type == 'task') {
                $task = $tasks->get($todo->related_id);
                if ($task) {
                    $startDate = $task->start_date->toDateString();
                    $endDate = $task->end_date->toDateString();
                    
                    if ($today < $startDate) {
                        $status = '普通';
                        $statusClass = 'bg-secondary';
                    } elseif ($today > $startDate && $today < $endDate) {
                        $status = '紧急';
                        $statusClass = 'bg-warning';
                    } else {
                        $status = '特急';
                        $statusClass = 'bg-danger';
                    }
                    
                    $todoTasks[] = [
                        'todo' => $todo,
                        'task' => $task,
                        'project' => $task->project,
                        'taskType' => $task->taskType,
                        'status' => $status,
                        'statusClass' => $statusClass,
                        'todo_type' => $todo->type,
                    ];
                }
            } elseif ($todo->related_type == 'extension_application') {
                $application = $extensions->get($todo->related_id);
                if ($application) {
                    $extensionTodos[] = [
                        'todo' => $todo,
                        'application' => $application,
                        'task' => $application->task,
                        'project' => $application->task ? $application->task->project : null,
                    ];
                }
            }
        }
        
        // 按截止日期排序（近期在前）
        usort($todoTasks, function ($a, $b) {
            return $a['task']->end_date->timestamp - $b['task']->end_date->timestamp;
        });

        return view('todo.index', compact('todoTasks', 'extensionTodos', 'todos'));
    }

    public function complete(Todo $todo)
    {
        if ($todo->user_id != auth()->id()) {
            abort(403);
        }

        $todo->complete();
        return redirect()->back()->with('success', '待办事项已完成');
    }

    public function approveExtension(ExtensionApplication $application)
    {
        $this->blockIfProjectInactive($application);
        if ($application->approver_id != auth()->id()) {
            abort(403);
        }

        try {
            $this->extensionService->approve($application);
            return redirect()->back()->with('success', '延期申请已通过');
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function rejectExtension(Request $request, ExtensionApplication $application)
    {
        $this->blockIfProjectInactive($application);
        if ($application->approver_id != auth()->id()) {
            abort(403);
        }

        try {
            $this->extensionService->reject($application, $request->reason);
            return redirect()->back()->with('success', '延期申请已驳回');
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}