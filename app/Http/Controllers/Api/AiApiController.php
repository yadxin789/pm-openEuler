<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiDelegationToken;
use App\Models\AiDocTemplate;
use App\Models\Department;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeDoc;
use App\Models\Project;
use App\Models\Task;
use App\Models\Todo;
use App\Models\User;
use App\Services\AiDelegationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 供 AI（含外部 Agent）全面调取 PM 系统数据的 REST API。
 *
 * 认证：X-AI-Key（系统密钥）
 * 授权：X-AI-Token（用户委托令牌）—— 携带后，本次请求以该用户身份执行，
 *       数据可见范围与下载权限完全等同于该用户本人。
 */
class AiApiController extends Controller
{
    protected AiDelegationService $delegation;

    public function __construct(AiDelegationService $delegation)
    {
        $this->delegation = $delegation;
    }

    // ========== 能力清单 / 元信息 ==========

    public function meta()
    {
        return response()->json([
            'system' => 'PM 产品研发管理系统',
            'version' => '1.0',
            'base_url' => url('/api/ai'),
            'auth' => [
                'system_key' => ['header' => 'X-AI-Key', 'query' => 'api_key'],
                'delegation' => ['header' => 'X-AI-Token', 'query' => 'ai_token'],
            ],
            'delegation_note' => '携带用户委托令牌后，可见范围与下载权限等同于该用户本人；'
                . '涉及文件正文下载与一切写操作必须携带。',
            'endpoints' => [
                ['path' => '/overview', 'method' => 'GET', 'desc' => '系统概览统计'],
                ['path' => '/projects', 'method' => 'GET', 'params' => 'status, keyword, limit', 'desc' => '项目列表'],
                ['path' => '/projects/{id}', 'method' => 'GET', 'desc' => '项目详情（含任务）'],
                ['path' => '/tasks', 'method' => 'GET', 'params' => 'status, keyword, executor, limit', 'desc' => '任务列表'],
                ['path' => '/tasks/{id}', 'method' => 'GET', 'desc' => '任务详情（含文件）'],
                ['path' => '/users', 'method' => 'GET', 'params' => 'keyword, limit', 'desc' => '用户列表'],
                ['path' => '/departments', 'method' => 'GET', 'desc' => '部门列表'],
                ['path' => '/knowledge-categories', 'method' => 'GET', 'desc' => '知识库分类树'],
                ['path' => '/knowledge', 'method' => 'GET', 'params' => 'keyword, category, limit', 'desc' => '知识库列表'],
                ['path' => '/knowledge/{id}', 'method' => 'GET', 'desc' => '知识库详情（完整文档树）'],
                ['path' => '/files', 'method' => 'GET', 'params' => 'task_id, status, keyword, limit', 'desc' => '任务文件列表'],
                ['path' => '/files/{id}', 'method' => 'GET', 'desc' => '文件详情'],
                ['path' => '/files/{id}/versions', 'method' => 'GET', 'desc' => '文件历史版本'],
                ['path' => '/files/{id}/content', 'method' => 'GET', 'desc' => '文件正文（仅文本类，需委托令牌）'],
                ['path' => '/files/{id}/download', 'method' => 'GET', 'desc' => '下载文件（需委托令牌 + download 权限）'],
                ['path' => '/department-files', 'method' => 'GET', 'params' => 'category, keyword, limit', 'desc' => '支援文件（部门资料）'],
                ['path' => '/department-files/{id}/download', 'method' => 'GET', 'desc' => '下载支援文件（需委托令牌）'],
                ['path' => '/templates', 'method' => 'GET', 'desc' => 'AI 文档模板列表'],
                ['path' => '/templates/{id}', 'method' => 'GET', 'desc' => '模板全文 + 范例 + 支援文件内容'],
                ['path' => '/todos', 'method' => 'GET', 'params' => 'completed, limit', 'desc' => '待办事项'],
            ],
            'write_endpoints' => [
                'note' => '所有写操作均为两阶段：先 POST 创建提案拿到 proposal_id 与 diff，'
                    . '再由授权人在「AI 授权中心」确认后生效。',
                'base' => '/api/ai/write',
            ],
        ]);
    }

    // ========== 概览 ==========

    public function overview()
    {
        return response()->json([
            'projects' => [
                'total' => Project::count(),
                'in_progress' => Project::where('status', \App\States\Project\InProgress::class)->count(),
                'overdue' => Project::where('status', \App\States\Project\Overdue::class)->count(),
                'completed' => Project::where('status', \App\States\Project\Completed::class)->count(),
                'suspended' => Project::where('status', \App\States\Project\Suspended::class)->count(),
            ],
            'tasks' => [
                'total' => Task::count(),
                'pending' => Task::where('status', \App\States\Task\Pending::class)->count(),
                'in_progress' => Task::where('status', \App\States\Task\InProgress::class)->count(),
                'for_audit' => Task::where('status', \App\States\Task\ForAudit::class)->count(),
                'overdue' => Task::where('status', \App\States\Task\Overdue::class)->count(),
                'completed' => Task::whereIn('status', [\App\States\Task\Completed::class, \App\States\Task\OverdueCompleted::class])->count(),
            ],
            'users' => User::where('is_active', true)->count(),
            'departments' => Department::count(),
        ]);
    }

    // ========== 项目 ==========

    public function projects(Request $request)
    {
        $query = Project::with('creator')->latest();
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%")
                ->orWhere('product_name', 'like', "%{$keyword}%"));
        }
        if ($status = $request->input('status')) {
            $map = [
                'in_progress' => \App\States\Project\InProgress::class,
                'overdue' => \App\States\Project\Overdue::class,
                'completed' => \App\States\Project\Completed::class,
                'suspended' => \App\States\Project\Suspended::class,
            ];
            $query->where('status', $map[$status] ?? $status);
        }
        $projects = $query->limit(min(50, max(1, (int) $request->input('limit', 20))))->get();

        return response()->json(['data' => $projects->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'version' => $p->version,
            'product_name' => $p->product_name,
            'product_model' => $p->product_model,
            'progress' => (float) $p->progress,
            'status' => $p->status->label ?? get_class($p->status),
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'creator' => $p->creator?->name,
            'task_count' => $p->tasks_count ?? null,
        ])]);
    }

    public function projectShow($id)
    {
        $project = Project::with('creator')->withCount('tasks')->find($id);
        if (!$project) {
            return response()->json(['error' => '项目不存在'], 404);
        }

        $project->load(['tasks.executor', 'tasks.auditor', 'tasks.department']);

        return response()->json([
            'data' => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'version' => $project->version,
                'product_name' => $project->product_name,
                'product_model' => $project->product_model,
                'progress' => (float) $project->progress,
                'status' => $project->status->label ?? get_class($project->status),
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'creator' => $project->creator?->name,
                'tasks' => $project->tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'status' => $t->status->label ?? get_class($t->status),
                    'department' => $t->department?->name,
                    'executor' => $t->executor?->name,
                    'auditor' => $t->auditor?->name,
                    'start_date' => $t->start_date?->toDateString(),
                    'end_date' => $t->end_date?->toDateString(),
                ]),
            ],
        ]);
    }

    // ========== 任务 ==========

    public function tasks(Request $request)
    {
        $query = Task::with(['project', 'executor', 'auditor'])->latest();
        if ($keyword = $request->input('keyword')) {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if ($status = $request->input('status')) {
            $map = [
                'pending' => \App\States\Task\Pending::class,
                'in_progress' => \App\States\Task\InProgress::class,
                'for_audit' => \App\States\Task\ForAudit::class,
                'overdue' => \App\States\Task\Overdue::class,
                'completed' => \App\States\Task\Completed::class,
            ];
            $query->where('status', $map[$status] ?? $status);
        }
        if ($executor = $request->input('executor')) {
            $query->whereHas('executor', fn ($q) => $q->where('name', 'like', "%{$executor}%"));
        }
        $tasks = $query->limit(min(50, max(1, (int) $request->input('limit', 20))))->get();

        return response()->json(['data' => $tasks->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'project' => $t->project?->name,
            'status' => $t->status->label ?? get_class($t->status),
            'executor' => $t->executor?->name,
            'auditor' => $t->auditor?->name,
            'start_date' => $t->start_date?->toDateString(),
            'end_date' => $t->end_date?->toDateString(),
            'is_overdue' => (bool) $t->is_overdue,
        ])]);
    }

    /**
     * 任务详情（含文件清单与 AI 可执行动作）
     */
    public function taskShow($id)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);

        $task = Task::with(['project', 'department', 'taskType', 'executor', 'auditor', 'files'])->find($id);
        if (!$task) {
            return response()->json(['error' => '任务不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canReadTask($user, $task)) {
            return response()->json(['error' => '无权访问该任务'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['data' => [
            'id' => $task->id,
            'name' => $task->name,
            'project_id' => $task->project_id,
            'project' => $task->project?->name,
            'department' => $task->department?->name,
            'task_type' => $task->taskType?->name,
            'status' => $task->status->label ?? get_class($task->status),
            'executor' => $task->executor?->name,
            'executor_id' => $task->executor_id,
            'auditor' => $task->auditor?->name,
            'auditor_id' => $task->auditor_id,
            'start_date' => $task->start_date?->toDateString(),
            'end_date' => $task->end_date?->toDateString(),
            'is_overdue' => (bool) $task->is_overdue,
            'extension_days' => $task->extension_days,
            'files' => $task->files->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->original_name ?: $f->name,
                'version' => $f->version,
                'status' => $f->status,
                'can_download' => $this->delegation->canDownloadFile($user, $f),
            ]),
            // AI 可发起的写动作（仍需走提案 + 人工确认）
            'ai_capabilities' => [
                'upload_file' => $this->delegation->canUploadFile($user, $task),
                'update_file' => $this->delegation->canUpdateFile($user, $task),
                'audit' => $this->delegation->canAudit($user, $task),
                'apply_extension' => $this->delegation->canApplyExtension($user, $task),
            ],
        ]]);
    }

    // ========== 用户 / 部门 ==========

    public function users(Request $request)
    {
        $query = User::with(['role', 'department'])->where('is_active', true);
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('username', 'like', "%{$keyword}%"));
        }
        $users = $query->limit(min(50, max(1, (int) $request->input('limit', 50))))->get();

        return response()->json(['data' => $users->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'role' => $u->role?->name,
            'department' => $u->department?->name,
        ])]);
    }

    public function departments()
    {
        $departments = Department::withCount('users')->orderBy('sort_order')->get();

        return response()->json(['data' => $departments->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'type' => $d->type,
            'member_count' => $d->users_count,
        ])]);
    }

    // ========== 知识库 ==========

    public function knowledge(Request $request)
    {
        $query = Knowledge::with(['category', 'author', 'docs' => fn ($q) => $q->orderBy('sort')->orderBy('id')]);

        // 可见性：未共享的知识库仅作者本人与管理员可见（与 Web 侧一致）
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);
        $user = $this->delegation->user();
        if (!$user || !$user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('is_share', true);
                if ($user) {
                    $q->orWhere('admin_id', $user->id);
                }
            });
        }

        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$keyword}%")
                ->orWhere('desc', 'like', "%{$keyword}%")
                ->orWhereHas('docs', fn ($d) => $d->where('title', 'like', "%{$keyword}%")));
        }
        if ($category = $request->input('category')) {
            $query->where('cate_id', $category);
        }
        $knowledges = $query->latest()->limit(min(50, max(1, (int) $request->input('limit', 20))))->get();

        return response()->json(['data' => $knowledges->map(fn ($k) => [
            'id' => $k->id,
            'title' => $k->title,
            'category_id' => $k->cate_id,
            'category' => $k->category?->title,
            'desc' => $k->desc,
            'is_share' => (bool) $k->is_share,
            'author' => $k->author?->name,
            'can_edit' => $user ? $this->delegation->canWriteKnowledge($user, $k) : false,
            'docs' => $k->docs->map(fn ($d) => [
                'id' => $d->id,
                'pid' => $d->pid,
                'type' => $d->type,
                'type_label' => KnowledgeDoc::typeLabel((int) $d->type),
                'title' => $d->title,
                'content' => $d->md_content ?: $d->content,
            ]),
        ])]);
    }

    /**
     * 知识库详情：返回完整文档树（含章节 / 链接，不再只返回 type=1）
     */
    public function knowledgeShow($id)
    {
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);
        $knowledge = Knowledge::with(['category', 'author', 'docs'])->find($id);
        if (!$knowledge) {
            return response()->json(['error' => '知识库不存在'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->delegation->user();
        if ($user && !$this->delegation->canReadKnowledge($user, $knowledge)) {
            return response()->json(['error' => '无权访问该知识库'], Response::HTTP_FORBIDDEN);
        }
        if (!$user && !$knowledge->is_share) {
            return response()->json(['error' => '该知识库未共享，需携带委托令牌访问'], Response::HTTP_FORBIDDEN);
        }

        $docs = $knowledge->docs->sortBy(['sort', 'id'])->values();

        return response()->json(['data' => [
            'id' => $knowledge->id,
            'title' => $knowledge->title,
            'category' => $knowledge->category?->title,
            'desc' => $knowledge->desc,
            'is_share' => (bool) $knowledge->is_share,
            'author' => $knowledge->author?->name,
            'can_edit' => $user ? $this->delegation->canWriteKnowledge($user, $knowledge) : false,
            'docs' => $docs->map(fn ($d) => [
                'id' => $d->id,
                'pid' => $d->pid,
                'type' => $d->type,
                'type_label' => KnowledgeDoc::typeLabel((int) $d->type),
                'title' => $d->title,
                'content' => $d->md_content ?: $d->content,
                'link' => $d->link,
                'sort' => $d->sort,
            ]),
        ]]);
    }

    /**
     * 知识库分类树
     */
    public function knowledgeCategories()
    {
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);
        $categories = KnowledgeCategory::orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => $categories->map(fn ($c) => [
            'id' => $c->id,
            'pid' => $c->pid,
            'title' => $c->title,
            'desc' => $c->desc,
            'knowledge_count' => $c->knowledges()->count(),
        ])]);
    }

    // ========== 文件 ==========

    public function files(Request $request)
    {
        $query = File::with(['task.project', 'uploader'])->latest();

        // 按任务过滤：AI 常按「这个任务有哪些文件」检索
        if ($taskId = $request->input('task_id')) {
            $query->where('task_id', $taskId);
        }
        if ($projectId = $request->input('project_id')) {
            $query->whereHas('task', fn ($q) => $q->where('project_id', $projectId));
        }
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('original_name', 'like', "%{$keyword}%"));
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $files = $query->limit(min(50, max(1, (int) $request->input('limit', 20))))->get();

        $user = $this->delegation->user();

        return response()->json(['data' => $files->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->original_name ?: $f->name,
            'version' => $f->version,
            'size' => $f->size,
            'mime_type' => $f->mime_type,
            'status' => $f->status,
            'task_id' => $f->task_id,
            'task' => $f->task?->name,
            'project' => $f->task?->project?->name,
            'uploader' => $f->uploader?->name,
            'can_download' => $user ? $this->delegation->canDownloadFile($user, $f) : false,
        ])]);
    }

    /**
     * 文件详情
     */
    public function fileShow($id)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);

        $file = File::with(['task.project', 'uploader', 'auditor'])->find($id);
        if (!$file) {
            return response()->json(['error' => '文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canReadFile($user, $file)) {
            return response()->json(['error' => '无权访问该文件'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['data' => [
            'id' => $file->id,
            'name' => $file->original_name ?: $file->name,
            'stored_name' => $file->name,
            'version' => $file->version,
            'size' => $file->size,
            'mime_type' => $file->mime_type,
            'status' => $file->status,
            'task_id' => $file->task_id,
            'task' => $file->task?->name,
            'project' => $file->task?->project?->name,
            'uploader' => $file->uploader?->name,
            'auditor' => $file->auditor?->name,
            'audit_time' => $file->audit_time?->toDateTimeString(),
            'audit_reason' => $file->audit_reason,
            'created_at' => $file->created_at?->toDateTimeString(),
            'can_download' => $this->delegation->canDownloadFile($user, $file),
            'can_preview' => $this->delegation->canPreviewFile($user, $file),
        ]]);
    }

    /**
     * 文件历史版本
     */
    public function fileVersions($id)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);

        $file = File::with('versions')->find($id);
        if (!$file) {
            return response()->json(['error' => '文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canReadFile($user, $file)) {
            return response()->json(['error' => '无权访问该文件'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['data' => $file->versions->map(fn ($v) => [
            'id' => $v->id,
            'version' => $v->version,
            'name' => $v->original_name ?: $v->name,
            'size' => $v->size,
            'created_at' => $v->created_at?->toDateTimeString(),
        ])]);
    }

    /**
     * 读取文件正文（仅文本类，供 AI 直接阅读内容）
     */
    public function fileContent($id)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);
        $this->delegation->requireScope(AiDelegationToken::SCOPE_DOWNLOAD);

        $file = File::find($id);
        if (!$file) {
            return response()->json(['error' => '文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canDownloadFile($user, $file)) {
            return response()->json(['error' => '当前授权人无权读取该文件正文'], Response::HTTP_FORBIDDEN);
        }

        $disk = Storage::disk('file_repository');
        if (!$disk->exists($file->path)) {
            return response()->json(['error' => '物理文件不存在'], Response::HTTP_NOT_FOUND);
        }

        if (!str_starts_with((string) $file->mime_type, 'text/')
            && !in_array(strtolower(pathinfo($file->original_name ?: $file->name, PATHINFO_EXTENSION)), ['txt', 'csv', 'md', 'json', 'log'], true)) {
            return response()->json([
                'error' => '该文件不是纯文本，无法直接返回正文，请改用 /files/{id}/download',
                'mime_type' => $file->mime_type,
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return response()->json(['data' => [
            'id' => $file->id,
            'name' => $file->original_name ?: $file->name,
            'content' => $disk->get($file->path),
        ]]);
    }

    /**
     * 下载文件（权限完全等同于授权人本人的下载权限）
     */
    public function fileDownload($id)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_DOWNLOAD);

        $file = File::find($id);
        if (!$file) {
            return response()->json(['error' => '文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canDownloadFile($user, $file)) {
            return response()->json(['error' => '当前授权人无权下载该文件'], Response::HTTP_FORBIDDEN);
        }

        $path = storage_path('app/file-repository/' . $file->path);
        if (!file_exists($path)) {
            return response()->json(['error' => '物理文件不存在'], Response::HTTP_NOT_FOUND);
        }

        return response()->download($path, $file->original_name ?: $file->name);
    }

    // ========== 待办 ==========

    public function todos(Request $request)
    {
        $query = Todo::with('user')->latest();
        $completed = $request->input('completed');
        if (in_array($completed, [true, 'true', 'completed'], true)) {
            $query->where('status', 'completed');
        } elseif (in_array($completed, [false, 'false', 'pending'], true)) {
            $query->where('status', '!=', 'completed');
        }
        $todos = $query->limit(min(50, max(1, (int) $request->input('limit', 50))))->get();

        return response()->json(['data' => $todos->map(fn ($t) => [
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'type' => $t->type,
            'related_type' => $t->related_type,
            'related_id' => $t->related_id,
            'user' => $t->user?->name,
            'completed_at' => $t->completed_at?->toDateTimeString(),
        ])]);
    }

    // ========== 文档模板（供外部 AI 生成文档时选用）==========

    public function templates()
    {
        $templates = AiDocTemplate::where('enabled', true)->get();

        return response()->json(['data' => $templates->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'category' => $t->category,
            'description' => $t->description,
            'has_template' => (bool) $t->template_path,
            'has_example' => (bool) $t->example_path,
            'support_files' => array_map('basename', array_filter((array) $t->support_files)),
        ])]);
    }

    /**
     * 模板全文：模板文件 + 范例文件 + 支援文件 + 生成指引。
     * 外部 AI 据此即可完整复现系统内置「文档生成 Skill」的取数能力。
     */
    public function templateShow($id)
    {
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);

        $template = AiDocTemplate::where('enabled', true)->find($id);
        if (!$template) {
            return response()->json(['error' => '模板不存在或已停用'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => [
            'id' => $template->id,
            'name' => $template->name,
            'category' => $template->category,
            'description' => $template->description,
            'prompt_guide' => $template->prompt_guide,
            'template_content' => $template->templateContent(),
            'example_content' => $template->exampleContent(),
            'support_contents' => $template->supportContents(),
        ]]);
    }

    // ========== 支援文件（部门资料文件）==========

    public function departmentFiles(Request $request)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_READ);

        $query = DepartmentFile::with(['department', 'uploader'])->latest();

        // 与支援中心一致：按 visibility 收敛到授权人可见范围
        if (!$user->isAdmin()) {
            $query->where(function ($scope) use ($user) {
                $scope->where('visibility', 'all')
                    ->orWhere(fn ($q) => $q->where('visibility', 'department')
                        ->where('department_id', $user->department_id))
                    ->orWhere(fn ($q) => $q->where('visibility', 'private')
                        ->where('uploader_id', $user->id));
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('original_name', 'like', "%{$keyword}%"));
        }

        $files = $query->limit(min(50, max(1, (int) $request->input('limit', 20))))->get();

        return response()->json(['data' => $files->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->original_name ?: $f->name,
            'category' => $f->category,
            'category_name' => $f->getCategoryNameAttribute(),
            'visibility' => $f->visibility,
            'visibility_name' => $f->getVisibilityNameAttribute(),
            'version' => $f->version,
            'size' => $f->size,
            'mime_type' => $f->mime_type,
            'department' => $f->department?->name,
            'uploader' => $f->uploader?->name,
            'can_edit' => $this->delegation->canWriteDepartmentFile($user, $f),
        ])]);
    }

    public function departmentFileDownload($id)
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_DOWNLOAD);

        $file = DepartmentFile::find($id);
        if (!$file) {
            return response()->json(['error' => '支援文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canReadDepartmentFile($user, $file)) {
            return response()->json(['error' => '当前授权人无权访问该支援文件'], Response::HTTP_FORBIDDEN);
        }

        $path = storage_path('app/file-repository/' . $file->path);
        if (!file_exists($path)) {
            return response()->json(['error' => '物理文件不存在'], Response::HTTP_NOT_FOUND);
        }

        return response()->download($path, $file->original_name ?: $file->name);
    }
}
