<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Project;
use App\Models\Todo;
use App\Models\ExtensionApplication;
use App\Models\ProjectLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Services\TaskService;
use App\Http\Requests\StoreTaskFileRequest;
use App\Http\Requests\ApproveTaskRequest;
use App\States\Task\ForAudit;
use App\States\Task\Completed;
use App\States\Task\Rejected;
use App\Notifications\AuditResultNotification;
use App\Notifications\AuditRequestNotification;
use App\Notifications\SystemNotification;
use App\Notifications\ExtensionResultNotification;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * 禁止对暂停状态项目的任务执行操作
     */
    protected function blockIfProjectInactive(Task $task): void
    {
        $task->loadMissing('project');
        $projectStatus = get_class($task->project->status);
        if ($projectStatus === \App\States\Project\Suspended::class) {
            abort(403, '项目已暂停，无法执行任务操作。请等待项目恢复后再试。');
        }
    }

    public function index()
    {
        $user = auth()->user();
        
        if ($user->isTaskExecutor()) {
            $tasks = Task::where('executor_id', $user->id)
                ->whereHas('project', function($q) {
                    $q->whereNotIn('status', [
                        \App\States\Project\Suspended::class,
                    ]);
                })
                ->with('project')->orderBy('end_date', 'asc')->paginate(10);
        } elseif ($user->isTaskAuditor()) {
            $tasks = Task::where('auditor_id', $user->id)
                ->whereHas('project', function($q) {
                    $q->whereNotIn('status', [
                        \App\States\Project\Suspended::class,
                    ]);
                })
                ->with('project')->orderBy('end_date', 'asc')->paginate(10);
        } else {
            $tasks = Task::with('project')->orderBy('end_date', 'asc')->paginate(10);
        }

        return view('task.index', compact('tasks'));
    }

    /**
     * 我的任务列表 - 任务执行人视图
     */
    public function myTasks()
    {
        $user = auth()->user();
        
        // 获取用户作为执行者的所有任务（排除已暂停项目的任务）
        $allTasks = Task::where('executor_id', $user->id)
            ->whereHas('project', function($q) {
                $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]);
            })
            ->with(['project', 'taskType'])
            ->orderBy('end_date', 'asc')
            ->get();
        
        // 按状态分组（互斥区域，覆盖所有 Task State）
        $pendingTasks = $allTasks->filter(function($task) {
            // 未执行：Pending 或 Rejected 状态
            return get_class($task->status) === \App\States\Task\Pending::class || 
                   get_class($task->status) === \App\States\Task\Rejected::class;
        });
        
        $forAuditTasks = $allTasks->filter(function($task) {
            // 待审核：ForAudit 状态
            return get_class($task->status) === \App\States\Task\ForAudit::class;
        });
        
        $completedTasks = $allTasks->filter(function($task) {
            // 已完成：Completed 或 OverdueCompleted（逾期完成）
            return get_class($task->status) === \App\States\Task\Completed::class ||
                   get_class($task->status) === \App\States\Task\OverdueCompleted::class;
        });
        
        $overdueTasks = $allTasks->filter(function($task) {
            // 已逾期：Overdue 或 InProgress 状态（超期但仍未完成）
            return get_class($task->status) === \App\States\Task\Overdue::class ||
                   get_class($task->status) === \App\States\Task\InProgress::class;
        });
        
        return view('task.my-tasks', compact(
            'pendingTasks',
            'forAuditTasks',
            'completedTasks',
            'overdueTasks'
        ));
    }

    /**
     * 我的审核任务列表 - 任务审核人视图
     */
    public function myAuditTasks()
    {
        $user = auth()->user();
        
        // 获取用户作为审核者的所有任务（排除暂停项目的任务）
        $allTasks = Task::where('auditor_id', $user->id)
            ->whereHas('project', function($q) {
                $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]);
            })
            ->with(['project', 'taskType'])
            ->orderBy('end_date', 'asc')
            ->get();
        
        // 按状态分组（互斥，覆盖所有 Task State）
        $pendingTasks = $allTasks->filter(function($task) {
            // 未提交：Pending 或 Rejected 状态
            return get_class($task->status) === \App\States\Task\Pending::class || 
                   get_class($task->status) === \App\States\Task\Rejected::class;
        });
        
        $forAuditTasks = $allTasks->filter(function($task) {
            // 待审核：ForAudit 状态
            return get_class($task->status) === \App\States\Task\ForAudit::class;
        });
        
        $completedTasks = $allTasks->filter(function($task) {
            // 已完成：Completed 或 OverdueCompleted（逾期完成）
            return get_class($task->status) === \App\States\Task\Completed::class ||
                   get_class($task->status) === \App\States\Task\OverdueCompleted::class;
        });
        
        $overdueTasks = $allTasks->filter(function($task) {
            // 已逾期：Overdue 或 InProgress 状态
            return get_class($task->status) === \App\States\Task\Overdue::class ||
                   get_class($task->status) === \App\States\Task\InProgress::class;
        });
        
        // 调试日志：验证互斥性
        \Log::info('我的审核任务列表统计', [
            'total' => $allTasks->count(),
            'pending' => $pendingTasks->count(),
            'for_audit' => $forAuditTasks->count(),
            'completed' => $completedTasks->count(),
            'overdue' => $overdueTasks->count(),
            'sum' => $pendingTasks->count() + $forAuditTasks->count() + $completedTasks->count() + $overdueTasks->count(),
        ]);
        
        return view('task.my-audit-tasks', compact(
            'pendingTasks',
            'forAuditTasks',
            'completedTasks',
            'overdueTasks'
        ));
    }

    public function show(Task $task)
    {
        Gate::authorize('view', $task);

        $files = $task->files()->with('uploader', 'auditor')->get();
        $extensionApplications = $task->extensionApplications()->with('applicant', 'approver')->get();
        return view('task.show', compact('task', 'files', 'extensionApplications'));
    }

    public function uploadFile(Task $task)
    {
        // 先自己跑一遍权限判断，拿到具体的拒绝原因，给用户友好的提示（而不是光秃秃的 403）
        $userId = auth()->check() ? auth()->id() : null;
        $denyReason = null;

        if (!$userId) {
            $denyReason = '请先登录后再上传文件。';
        } elseif (!$task->project) {
            $denyReason = '该任务未关联到有效项目，无法上传文件。';
        } elseif ($userId !== (int)$task->executor_id) {
            $denyReason = '只有【任务执行人】才能上传任务文件。当前登录账号不是该任务的执行人。';
        } elseif ($task->project->isInactive()) {
            $denyReason = '当前项目已暂停（'.$task->project->status->label().'），无法上传文件。请等待项目恢复后再操作。';
        } elseif ($task->hasPendingFile()) {
            $denyReason = '当前任务已提交文件、正在审核中（'.$task->status->label().'）。如需变更文件，请等审核人驳回后再上传。';
        } elseif (($task->status->equals(\App\States\Task\Completed::class)
                || $task->status->equals(\App\States\Task\OverdueCompleted::class))
            && $task->files()->where('status', 'approved')->exists()) {
            $denyReason = '任务已完成（'.$task->status->label().'）且已有已通过的文件，请在文件列表中使用「更新」提交新版本。';
        } elseif (!$task->canUploadFile($userId)) {
            $denyReason = '当前任务状态（'.$task->status->label().'）不允许上传文件。';
        }

        // 调试日志（保留，方便以后排查）
        if (auth()->check()) {
            \Log::debug('[task.uploadFile] 请求记录', [
                'task_id'            => $task->id,
                'user_id'            => $userId,
                'executor_id'        => $task->executor_id,
                'project_status'     => $task->project ? get_class($task->project->status) : null,
                'task_status'        => get_class($task->status),
                'has_pending_file'   => $task->hasPendingFile(),
                'canUploadFile'      => $task->canUploadFile($userId),
                'deny_reason'        => $denyReason,
            ]);
        }

        if ($denyReason !== null) {
            // 返回上一页，带明确的错误提示。如果没有上一页就回到我的待执行任务页
            $fallback = redirect()->route('task.my-tasks.pending-execution');
            return redirect()->back()->with('error', '无法上传文件：'.$denyReason)->setIntendedUrl($fallback->getTargetUrl());
        }

        Gate::authorize('uploadFile', $task); // 第二道防线（Policy 兜底）
        return view('task.upload', compact('task'));
    }

    public function storeFile(StoreTaskFileRequest $request, Task $task, TaskService $taskService)
    {
        $this->blockIfProjectInactive($task);
        
        // 检查PHP级别的上传错误
        if ($request->hasFile('file') && $request->file('file')->getError()) {
            $error = $this->getUploadErrorMessage($request->file('file')->getError());
            return redirect()
                ->back()
                ->withInput()
                ->with('upload_error', $error);
        }
        
        try {
            $uploadedFile = $request->file('file');

            $taskService->storeFile($task, $uploadedFile, auth()->id());
            $taskService->refreshProjectProgress($task);

            // 🎯 关键点：上传成功后不要再回到 upload 页！
            // 因为 storeFile 会把任务流转为 ForAudit，回到 upload 页立刻被权限判断 403。
            // 正确跳转：回到任务详情页，显示"文件已提交，等待审核人审核"的提示。
            return redirect()
                ->route('task.show', $task)
                ->with('success', '文件上传成功，已提交等待审核人审核。审核结果出来后你会收到通知。');
        } catch (\Throwable $exception) {
            \Log::error('任务文件上传失败', [
                'task_id' => $task->id,
                'user_id' => auth()->id(),
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('upload_error', '文件上传失败，请重试。原因：'.$exception->getMessage());
        }
    }

    /**
     * 获取PHP上传错误的详细提示信息
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        $uploadedFile = request()->file('file');
        $fileName = $uploadedFile ? $uploadedFile->getClientOriginalName() : '未知文件';
        $fileSize = $uploadedFile ? round($uploadedFile->getSize() / 1024 / 1024, 2) : '未知';

        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return "文件上传失败：\n" .
                       "文件名：{$fileName}\n" .
                       "文件大小：{$fileSize} MB\n\n" .
                       "错误原因：文件大小超过了PHP配置（php.ini）中的 upload_max_filesize 限制。\n" .
                       "解决方案：请联系管理员修改 php.ini 中的 upload_max_filesize 和 post_max_size 配置。";
            
            case UPLOAD_ERR_FORM_SIZE:
                return "文件上传失败：\n" .
                       "文件名：{$fileName}\n\n" .
                       "错误原因：文件大小超过了HTML表单中MAX_FILE_SIZE字段限制。\n" .
                       "解决方案：请检查表单配置。";
            
            case UPLOAD_ERR_PARTIAL:
                return "文件上传失败：\n" .
                       "文件名：{$fileName}\n\n" .
                       "错误原因：文件只有部分被上传，可能是网络中断或上传超时。\n" .
                       "解决方案：请检查网络连接后重试，或联系管理员增加 max_execution_time 配置。";
            
            case UPLOAD_ERR_NO_FILE:
                return "文件上传失败：\n\n" .
                       "错误原因：没有文件被上传。\n" .
                       "解决方案：请重新选择文件后上传。";
            
            case UPLOAD_ERR_NO_TMP_DIR:
                return "文件上传失败：\n\n" .
                       "错误原因：服务器缺少临时文件夹。\n" .
                       "解决方案：请联系管理员检查服务器配置。";
            
            case UPLOAD_ERR_CANT_WRITE:
                return "文件上传失败：\n\n" .
                       "错误原因：文件写入磁盘失败。\n" .
                       "解决方案：请联系管理员检查服务器磁盘空间和权限。";
            
            case UPLOAD_ERR_EXTENSION:
                return "文件上传失败：\n\n" .
                       "错误原因：PHP扩展阻止了文件上传。\n" .
                       "解决方案：请联系管理员检查PHP扩展配置。";
            
            default:
                return "文件上传失败：\n" .
                       "文件名：{$fileName}\n\n" .
                       "错误原因：未知错误（错误代码：{$errorCode}）。\n" .
                       "解决方案：请联系管理员检查服务器日志。";
        }
    }

    public function audit(Task $task)
    {
        Gate::authorize('audit', $task); // Policy 内已统一判断：角色 + ForAudit 状态 + 必须有 pending 文件 + 项目激活
        $file = $task->pendingFile();
        // 防御性兜底（Policy 已经保证 pendingFile 存在，但防数据不一致）
        abort_if(!$file, 409, '没有待审核的文件，请刷新页面后重试');
        return view('task.audit', compact('task', 'file'));
    }

    public function approve(ApproveTaskRequest $request, Task $task, TaskService $taskService)
    {
        Gate::authorize('audit', $task); // POST 也必须过 Gate，防止绕过前端直接发请求
        $taskService->approve($task, $request->reason, auth()->id());
        $taskService->refreshProjectProgress($task);

        return redirect()
            ->route('task.my-audit-tasks.pending-audit')
            ->with('success', '文件已通过');
    }

    public function reject(ApproveTaskRequest $request, Task $task, TaskService $taskService)
    {
        Gate::authorize('audit', $task);
        $taskService->reject($task, $request->reason, auth()->id());
        $taskService->refreshProjectProgress($task);

        return redirect()
            ->route('task.my-audit-tasks.pending-audit')
            ->with('success', '文件已驳回');
    }

    public function applyExtension(Task $task)
    {
        Gate::authorize('applyExtension', $task); // Policy 统一判断角色+状态+项目激活
        return view('task.apply-extension', compact('task'));
    }

    public function storeExtension(Request $request, Task $task)
    {
        $this->blockIfProjectInactive($task);
        Gate::authorize('applyExtension', $task);

        $maxDays = config('pm.extension.max_days', 90);

        $request->validate([
            'days' => ['required', 'integer', 'min:1', "max:{$maxDays}"],
            'reason' => 'required|string|max:500',
        ]);

        // 检查是否已有待审批的延期申请
        $existingApplication = ExtensionApplication::where('task_id', $task->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingApplication) {
            return redirect()->back()->with('error', '该任务已有待审批的延期申请，请等待审批结果');
        }

        $extensionApplication = ExtensionApplication::create([
            'task_id' => $task->id,
            'applicant_id' => auth()->id(),
            'approver_id' => $task->project->creator_id,
            'days' => $request->days,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        Todo::create([
            'user_id' => $task->project->creator_id,
            'type' => 'extension',
            'title' => "延期申请：【{$task->project->name}】{$task->name}",
            'related_type' => 'extension_application',
            'related_id' => $extensionApplication->id,
        ]);

        // 使用 Laravel Notification 系统通知项目创建人
        $creator = \App\Models\User::find($task->project->creator_id);
        if ($creator) {
            $creator->notify(new SystemNotification(
                title: '收到延期申请',
                content: "任务「{$task->name}」申请延期{$request->days}天：{$request->reason}",
                relatedType: 'task',
                relatedId: $task->id,
                senderId: auth()->id(),
                type: 'extension',
            ));
        }

        return redirect()->route('task.show', $task)->with('success', '延期申请已提交');
    }

    public function updateFile(Task $task)
    {
        Gate::authorize('updateFile', $task); // Policy 统一判断：角色（执行人/项目创建人） + Completed/OverdueCompleted 状态 + approved 文件 + 项目激活
        return view('task.update-file', compact('task'));
    }

    public function storeUpdate(StoreTaskFileRequest $request, Task $task, TaskService $taskService)
    {
        $this->blockIfProjectInactive($task);
        Gate::authorize('updateFile', $task);

        try {
            $taskService->updateFile($task, $request->file('file'), auth()->id());
            return redirect()->route('task.my-tasks')->with('success', '文件已更新，等待审核');
        } catch (\Throwable $exception) {
            \Log::error('文件更新失败', [
                'task_id' => $task->id,
                'user_id' => auth()->id(),
                'error' => $exception->getMessage(),
            ]);
            return redirect()->back()
                ->withInput()
                ->with('error', '更新失败。错误：' . $exception->getMessage());
        }
    }
    
    // ==================== 按状态拆分的执行人任务列表 ====================

    /**
     * 我的待执行任务列表（等待中、进行中、已逾期、已驳回）
     */
    public function myPendingExecutionTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'executor', [
            \App\States\Task\Pending::class,
            \App\States\Task\InProgress::class,
            \App\States\Task\Overdue::class,
            \App\States\Task\Rejected::class,
        ], '待执行');
    }

    /**
     * 我的逾期任务列表（分页+过滤）
     */
    public function myOverdueTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'executor', [\App\States\Task\Overdue::class], '逾期');
    }

    /**
     * 我的进行中任务列表（分页+过滤）
     */
    public function myInProgressTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'executor', [
            \App\States\Task\Pending::class,
            \App\States\Task\Rejected::class,
            \App\States\Task\InProgress::class,
            \App\States\Task\ForAudit::class,
        ], '进行中');
    }

    /**
     * 我的已完成任务列表（分页+过滤，含逾期完成）
     */
    public function myCompletedTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'executor', [
            \App\States\Task\Completed::class,
            \App\States\Task\OverdueCompleted::class,
        ], '已完成');
    }

    /**
     * 我的暂停任务列表（分页+过滤）
     */
    public function mySuspendedTasks(Request $request)
    {
        // 暂停状态的任务：项目被暂停时关联的任务
        $user = auth()->user();
        
        $query = Task::where('executor_id', $user->id)
            ->whereHas('project', function($q) {
                $q->where('status', \App\States\Project\Suspended::class);
            })
            ->with(['project', 'taskType']);
        
        // 应用筛选
        if ($request->filled('name')) {
            $query->whereHas('project', fn($q) => $q->where('name', 'like', '%'.$request->name.'%'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->date_to);
        }
        
        $tasks = $query->orderBy('end_date', 'asc')->paginate(15);
        $statusLabel = '暂停';
        
        return view('task.my-tasks-status', compact('tasks', 'statusLabel'));
    }

    // ==================== 按状态拆分的审核任务列表 ====================

    /**
     * 我的待审核任务列表（待审核、已逾期）
     */
    public function myPendingAuditTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'auditor', [
            \App\States\Task\ForAudit::class,
            \App\States\Task\Overdue::class,
        ], '待审核');
    }

    /**
     * 我的逾期审核任务列表（分页+过滤）
     */
    public function myOverdueAuditTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'auditor', [\App\States\Task\Overdue::class], '逾期');
    }

    /**
     * 我的进行中审核任务列表（分页+过滤）
     */
    public function myInProgressAuditTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'auditor', [
            \App\States\Task\Pending::class,
            \App\States\Task\Rejected::class,
            \App\States\Task\InProgress::class,
            \App\States\Task\ForAudit::class,
        ], '进行中');
    }

    /**
     * 我的已完成审核任务列表（分页+过滤，含逾期完成）
     */
    public function myCompletedAuditTasks(Request $request)
    {
        return $this->myTasksByStatus($request, 'auditor', [
            \App\States\Task\Completed::class,
            \App\States\Task\OverdueCompleted::class,
        ], '已完成');
    }

    /**
     * 我的暂停审核任务列表（分页+过滤）
     */
    public function mySuspendedAuditTasks(Request $request)
    {
        $user = auth()->user();
        
        $query = Task::where('auditor_id', $user->id)
            ->whereHas('project', function($q) {
                $q->where('status', \App\States\Project\Suspended::class);
            })
            ->with(['project', 'taskType']);
        
        if ($request->filled('name')) {
            $query->whereHas('project', fn($q) => $q->where('name', 'like', '%'.$request->name.'%'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->date_to);
        }
        
        $tasks = $query->orderBy('end_date', 'asc')->paginate(15);
        $statusLabel = '暂停';
        
        return view('task.my-audit-tasks-status', compact('tasks', 'statusLabel'));
    }

    /**
     * 按状态获取任务（分页+过滤）
     */
    private function myTasksByStatus(Request $request, string $role, array $statusClasses, string $statusLabel)
    {
        $user = auth()->user();
        $column = $role === 'executor' ? 'executor_id' : 'auditor_id';
        
        $query = Task::where($column, $user->id)
            ->whereIn('status', $statusClasses)
            ->whereHas('project', function($q) {
                $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]);
            })
            ->with(['project', 'taskType']);
        
        // 应用筛选
        if ($request->filled('name')) {
            $query->whereHas('project', fn($q) => $q->where('name', 'like', '%'.$request->name.'%'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->date_to);
        }
        
        $tasks = $query->orderBy('end_date', 'asc')->paginate(15);
        
        $view = $role === 'executor' ? 'task.my-tasks-status' : 'task.my-audit-tasks-status';
        
        return view($view, compact('tasks', 'statusLabel'));
    }

    /**
     * 刷新项目进度和状态
     */
    private function refreshProjectProgress($task)
    {
        try {
            // 重新计算项目进度
            $project = $task->project;
            $project->calculateProgress();
        } catch (\Exception $e) {
            \Log::warning('刷新项目进度失败: ' . $e->getMessage());
        }
    }

    /**
     * 向项目所有成员发送文件更新通知
     */
    private function notifyProjectMembers($task, $file)
    {
        try {
            $project = $task->project;

            // 获取项目所有成员（创建者、执行者、审核者）
            $memberIds = collect();
            $memberIds->push($project->creator_id);
            $memberIds->push($task->executor_id);
            $memberIds->push($task->auditor_id);

            // 去重并排除当前用户（审核人）
            $memberIds = $memberIds->unique()->diff([auth()->id()]);

            // 使用 Laravel Notification 系统通知每个成员
            foreach ($memberIds as $userId) {
                $user = \App\Models\User::find($userId);
                if ($user) {
                    $user->notify(new SystemNotification(
                        title: '项目文件已更新',
                        content: "【{$project->name}】任务「{$task->name}」的文件已更新至 v{$file->version}，请及时查看",
                        relatedType: 'file',
                        relatedId: $file->id,
                        senderId: auth()->id(),
                        type: 'info',
                    ));
                }
            }
        } catch (\Exception $e) {
            \Log::warning('发送项目成员通知失败: ' . $e->getMessage());
        }
    }
}