<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiChangeProposal;
use App\Models\AiDelegationToken;
use App\Models\DepartmentFile;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\Project;
use App\Models\Task;
use App\Services\AiChangeProposalService;
use App\Services\AiDelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 写入 API —— 全部为「两阶段」：
 *
 *   AI 调用 -> 生成待确认提案（不改任何业务数据）-> 返回 proposal_id + diff
 *   授权人在「AI 授权中心」审阅 -> 确认才生效 / 驳回即作废
 *
 * 权限：一律以委托令牌绑定的授权人身份判定。
 * 谁对这份数据有写权限，谁就可以授权 AI 写；AI 自己没有任何独立权限。
 */
class AiWriteApiController extends Controller
{
    public function __construct(
        protected AiDelegationService $delegation,
        protected AiChangeProposalService $proposals
    ) {
    }

    // ==================== 提案查询 ====================

    public function proposals(Request $request): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->proposals->expireStale();

        $query = AiChangeProposal::where('user_id', $user->id)->latest();
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->limit(50)->get()->map(fn ($p) => $this->proposalBrief($p)),
        ]);
    }

    public function proposalShow(int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $proposal = AiChangeProposal::find($id);

        if (!$proposal || !$this->delegation->canResolveProposal($user, $proposal)) {
            return response()->json(['error' => '提案不存在或无权查看'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $this->proposalBrief($proposal, true)]);
    }

    private function proposalBrief(AiChangeProposal $p, bool $withDiff = false): array
    {
        $data = [
            'id' => $p->id,
            'resource_type' => $p->resource_type,
            'resource_label' => $p->resourceLabel(),
            'resource_id' => $p->resource_id,
            'action' => $p->action,
            'action_label' => $p->actionLabel(),
            'status' => $p->status,
            'reason' => $p->reason,
            'expires_at' => $p->expires_at?->toDateTimeString(),
            'resolved_at' => $p->resolved_at?->toDateTimeString(),
            'result' => $p->result,
            'created_at' => $p->created_at?->toDateTimeString(),
            'confirm_url' => url('/ai-delegation'),
        ];
        if ($withDiff) {
            $data['diff'] = $p->diff();
            $data['before'] = $p->before;
            $data['after'] = $p->after;
        }
        return $data;
    }

    // ==================== 任务 ====================

    public function storeTask(Request $request): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'name' => 'required|string|max:200',
            'department_id' => 'nullable|integer|exists:departments,id',
            'task_type_id' => 'nullable|integer|exists:task_types,id',
            'executor_id' => 'required|integer|exists:users,id',
            'auditor_id' => 'required|integer|exists:users,id|different:executor_id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $project = Project::findOrFail($data['project_id']);
        if (!$this->delegation->canCreateTask($user, $project)) {
            return response()->json(['error' => '只有项目创建人或管理员可新建任务'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = $this->proposals->normalizeTaskCreate($data, $project);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->propose($user, AiChangeProposal::RESOURCE_TASK, 'create', $data, null);
    }

    public function updateTask(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $task = Task::find($id);
        if (!$task) {
            return response()->json(['error' => '任务不存在'], Response::HTTP_NOT_FOUND);
        }
        // 任务编辑权限对齐项目管理侧：管理员、项目创建人、执行人、审核人
        if (!$this->delegation->canManageTask($user, $task)) {
            return response()->json(['error' => '当前授权人无权编辑该任务'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:200',
            'department_id' => 'nullable|integer|exists:departments,id',
            'task_type_id' => 'nullable|integer|exists:task_types,id',
            'executor_id' => 'nullable|integer|exists:users,id',
            'auditor_id' => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->propose($user, AiChangeProposal::RESOURCE_TASK, 'update', $data, $task);
    }

    public function applyExtension(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $task = Task::find($id);
        if (!$task) {
            return response()->json(['error' => '任务不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canApplyExtension($user, $task)) {
            return response()->json(['error' => '当前授权人无权为该任务申请延期'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'days' => 'required|integer|min:1|max:' . config('pm.extension.max_days', 90),
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->propose($user, AiChangeProposal::RESOURCE_TASK, 'extension', $data, $task);
    }

    // ==================== 任务文件 ====================

    public function uploadTaskFile(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $task = Task::find($id);
        if (!$task) {
            return response()->json(['error' => '任务不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canUploadFile($user, $task)) {
            return response()->json(['error' => '当前授权人无权向该任务上传文件'], Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'file' => 'required|file|max:' . config('pm.max_upload_size', 20480),
            'reason' => 'nullable|string|max:500',
        ]);

        $staged = $this->proposals->stageUploadedFile($request->file('file'));

        return $this->propose(
            $user,
            AiChangeProposal::RESOURCE_FILE,
            'upload',
            $staged + ['reason' => $request->input('reason')],
            $task
        );
    }

    public function updateTaskFile(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $task = Task::find($id);
        if (!$task) {
            return response()->json(['error' => '任务不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canUpdateFile($user, $task)) {
            return response()->json(['error' => '当前授权人无权更新该任务的文件'], Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'file' => 'required|file|max:' . config('pm.max_upload_size', 20480),
            'reason' => 'nullable|string|max:500',
        ]);

        $staged = $this->proposals->stageUploadedFile($request->file('file'));

        return $this->propose(
            $user,
            AiChangeProposal::RESOURCE_FILE,
            'update',
            $staged + ['reason' => $request->input('reason')],
            $task
        );
    }

    public function auditTask(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $task = Task::find($id);
        if (!$task) {
            return response()->json(['error' => '任务不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canAudit($user, $task)) {
            return response()->json(['error' => '当前授权人无权审核该任务'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'result' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->propose(
            $user,
            AiChangeProposal::RESOURCE_FILE,
            $data['result'],
            $data,
            $task
        );
    }

    // ==================== 支援文件（部门资料文件）====================

    public function storeDepartmentFile(Request $request): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $data = $request->validate([
            'file' => 'required|file|max:' . config('pm.max_upload_size', 20480),
            'category' => 'required|in:' . implode(',', array_keys(DepartmentFile::categoryOptions())),
            'visibility' => 'required|in:' . implode(',', array_keys(DepartmentFile::visibilityOptions())),
            'reason' => 'nullable|string|max:500',
        ]);

        $staged = $this->proposals->stageUploadedFile($request->file('file'));

        return $this->propose(
            $user,
            AiChangeProposal::RESOURCE_DEPARTMENT_FILE,
            'create',
            array_merge($staged, [
                'category' => $data['category'],
                'visibility' => $data['visibility'],
                'reason' => $data['reason'] ?? null,
            ])
        );
    }

    public function updateDepartmentFile(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $file = DepartmentFile::find($id);
        if (!$file) {
            return response()->json(['error' => '支援文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteDepartmentFile($user, $file)) {
            return response()->json(['error' => '当前授权人无权更新该支援文件'], Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'file' => 'required|file|max:' . config('pm.max_upload_size', 20480),
            'reason' => 'nullable|string|max:500',
        ]);

        $staged = $this->proposals->stageUploadedFile($request->file('file'));

        return $this->propose(
            $user,
            AiChangeProposal::RESOURCE_DEPARTMENT_FILE,
            'update',
            $staged + ['reason' => $request->input('reason')],
            $file
        );
    }

    public function destroyDepartmentFile(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $file = DepartmentFile::find($id);
        if (!$file) {
            return response()->json(['error' => '支援文件不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteDepartmentFile($user, $file)) {
            return response()->json(['error' => '当前授权人无权删除该支援文件'], Response::HTTP_FORBIDDEN);
        }

        return $this->propose($user, AiChangeProposal::RESOURCE_DEPARTMENT_FILE, 'delete', [
            'reason' => $request->input('reason'),
        ], $file);
    }

    // ==================== 知识库 ====================

    public function storeKnowledge(Request $request): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'cate_id' => 'nullable|integer|exists:knowledge_categories,id',
            'desc' => 'nullable|string|max:500',
            'is_share' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->propose($user, AiChangeProposal::RESOURCE_KNOWLEDGE, 'create', $data);
    }

    public function updateKnowledge(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $knowledge = Knowledge::find($id);
        if (!$knowledge) {
            return response()->json(['error' => '知识库不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteKnowledge($user, $knowledge)) {
            return response()->json(['error' => '当前授权人无权编辑该知识库'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'cate_id' => 'nullable|integer|exists:knowledge_categories,id',
            'desc' => 'nullable|string|max:500',
            'is_share' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->propose($user, AiChangeProposal::RESOURCE_KNOWLEDGE, 'update', $data, $knowledge);
    }

    public function destroyKnowledge(Request $request, int $id): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $knowledge = Knowledge::find($id);
        if (!$knowledge) {
            return response()->json(['error' => '知识库不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteKnowledge($user, $knowledge)) {
            return response()->json(['error' => '当前授权人无权删除该知识库'], Response::HTTP_FORBIDDEN);
        }
        if ($knowledge->docs()->count() > 0) {
            return response()->json(['error' => '该知识库下仍有文档，请先清空文档'], Response::HTTP_CONFLICT);
        }

        return $this->propose($user, AiChangeProposal::RESOURCE_KNOWLEDGE, 'delete', [
            'reason' => $request->input('reason'),
        ], $knowledge);
    }

    // ==================== 知识库文档 ====================

    public function storeKnowledgeDoc(Request $request, int $knowledgeId): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $knowledge = Knowledge::find($knowledgeId);
        if (!$knowledge) {
            return response()->json(['error' => '知识库不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteKnowledge($user, $knowledge)) {
            return response()->json(['error' => '当前授权人无权编辑该知识库'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'type' => 'nullable|in:1,2,3',
            'pid' => 'nullable|integer',
            'md_content' => 'nullable|string',
            'link' => 'nullable|url|max:500',
            'reason' => 'nullable|string|max:500',
        ]);
        $data['knowledge_id'] = $knowledge->id;

        return $this->propose($user, AiChangeProposal::RESOURCE_KNOWLEDGE_DOC, 'create', $data);
    }

    public function updateKnowledgeDoc(Request $request, int $docId): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $doc = KnowledgeDoc::find($docId);
        if (!$doc) {
            return response()->json(['error' => '文档不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteKnowledgeDoc($user, $doc)) {
            return response()->json(['error' => '当前授权人无权编辑该文档'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'type' => 'nullable|in:1,2,3',
            'pid' => 'nullable|integer',
            'md_content' => 'nullable|string',
            'link' => 'nullable|url|max:500',
            'sort' => 'nullable|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->propose($user, AiChangeProposal::RESOURCE_KNOWLEDGE_DOC, 'update', $data, $doc);
    }

    public function destroyKnowledgeDoc(Request $request, int $docId): JsonResponse
    {
        $user = $this->delegation->requireUser();
        $this->delegation->requireScope(AiDelegationToken::SCOPE_WRITE);

        $doc = KnowledgeDoc::find($docId);
        if (!$doc) {
            return response()->json(['error' => '文档不存在'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->delegation->canWriteKnowledgeDoc($user, $doc)) {
            return response()->json(['error' => '当前授权人无权删除该文档'], Response::HTTP_FORBIDDEN);
        }

        return $this->propose($user, AiChangeProposal::RESOURCE_KNOWLEDGE_DOC, 'delete', [
            'reason' => $request->input('reason'),
        ], $doc);
    }

    // ==================== 内部 ====================

    /**
     * 统一落提案并返回 202（表示「已受理，等待人工确认」）。
     */
    private function propose(
        $user,
        string $resourceType,
        string $action,
        array $payload,
        $resource = null
    ): JsonResponse {
        $reason = $payload['reason'] ?? null;
        unset($payload['reason']);

        $proposal = $this->proposals->propose(
            $user,
            $this->delegation->token(),
            $resourceType,
            $action,
            $payload,
            $reason,
            $resource
        );

        return response()->json([
            'message' => '变更提案已创建，等待授权人确认后生效。AI 本身未修改任何数据。',
            'proposal_id' => $proposal->id,
            'status' => $proposal->status,
            'resource' => $proposal->resourceLabel(),
            'action' => $proposal->actionLabel(),
            'expires_at' => $proposal->expires_at?->toDateTimeString(),
            'diff' => $proposal->diff(),
            'confirm_url' => url('/ai-delegation'),
            'how_to_confirm' => '授权人登录 PM 系统进入「AI 授权中心」确认；'
                . '或调用 GET /api/ai/write/proposals/{id} 跟踪状态。',
        ], Response::HTTP_ACCEPTED);
    }
}
