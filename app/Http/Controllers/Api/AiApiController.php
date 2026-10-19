<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\Project;
use App\Models\Task;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 供 AI（含外部 Agent）全面调取 PM 系统数据的 REST API。
 * 通过 X-AI-Key 认证，返回干净的 JSON 数据。
 */
class AiApiController extends Controller
{
    // ========== 能力清单 / 元信息 ==========

    public function meta()
    {
        return response()->json([
            'system' => 'PM 产品研发管理系统',
            'version' => '1.0',
            'base_url' => url('/api/ai'),
            'auth' => ['header' => 'X-AI-Key', 'query' => 'api_key'],
            'endpoints' => [
                ['path' => '/overview', 'method' => 'GET', 'desc' => '系统概览统计'],
                ['path' => '/projects', 'method' => 'GET', 'params' => 'status, keyword, limit', 'desc' => '项目列表'],
                ['path' => '/projects/{id}', 'method' => 'GET', 'desc' => '项目详情（含任务）'],
                ['path' => '/tasks', 'method' => 'GET', 'params' => 'status, keyword, executor, limit', 'desc' => '任务列表'],
                ['path' => '/users', 'method' => 'GET', 'params' => 'keyword, limit', 'desc' => '用户列表'],
                ['path' => '/departments', 'method' => 'GET', 'desc' => '部门列表'],
                ['path' => '/knowledge', 'method' => 'GET', 'params' => 'keyword, limit', 'desc' => '知识库'],
                ['path' => '/files', 'method' => 'GET', 'params' => 'status, keyword, limit', 'desc' => '文件列表'],
                ['path' => '/todos', 'method' => 'GET', 'params' => 'completed, limit', 'desc' => '待办事项'],
                ['path' => '/templates', 'method' => 'GET', 'desc' => 'AI 文档模板列表'],
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
        $query = Knowledge::with(['category', 'author', 'docs' => fn ($q) => $q->where('type', KnowledgeDoc::TYPE_DOC)]);
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$keyword}%")
                ->orWhere('desc', 'like', "%{$keyword}%")
                ->orWhereHas('docs', fn ($d) => $d->where('title', 'like', "%{$keyword}%")));
        }
        $knowledges = $query->limit(min(20, max(1, (int) $request->input('limit', 10))))->get();

        return response()->json(['data' => $knowledges->map(fn ($k) => [
            'id' => $k->id,
            'title' => $k->title,
            'category' => $k->category?->name,
            'desc' => $k->desc,
            'author' => $k->author?->name,
            'docs' => $k->docs->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'content' => $d->md_content ?: $d->content,
            ]),
        ])]);
    }

    // ========== 文件 ==========

    public function files(Request $request)
    {
        $query = File::with(['task.project', 'uploader'])->latest();
        if ($keyword = $request->input('keyword')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('original_name', 'like', "%{$keyword}%"));
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $files = $query->limit(min(50, max(1, (int) $request->input('limit', 20))))->get();

        return response()->json(['data' => $files->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->original_name ?: $f->name,
            'version' => $f->version,
            'size' => $f->size,
            'status' => $f->status,
            'task' => $f->task?->name,
            'project' => $f->task?->project?->name,
            'uploader' => $f->uploader?->name,
        ])]);
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
        $templates = \App\Models\AiDocTemplate::where('enabled', true)->get();

        return response()->json(['data' => $templates->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'category' => $t->category,
            'description' => $t->description,
        ])]);
    }
}
