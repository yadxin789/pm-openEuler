<?php

namespace App\Services;

use App\Models\AiChangeProposal;
use App\Models\AiDelegationToken;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\OperationLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AI 变更提案引擎 —— 实现「AI 编辑前需用户同意，编辑结果需用户确认」。
 *
 * 流程：
 *   1) AI 调用写接口 -> propose()：只做权限校验 + 生成 before/after 预览，落库为 pending 提案，
 *      **不改动任何业务数据**，返回 proposal_id 与 diff 供用户审阅。
 *   2) 授权人在「AI 授权中心」审阅 diff -> confirm()：此时才真正执行写入。
 *      reject() 则丢弃，业务数据自始至终未被触碰。
 *
 * 权限：propose 阶段即按授权人身份校验，无权的操作根本生成不了提案。
 */
class AiChangeProposalService
{
    public const STAGING_DISK = 'ai_staging';

    public function __construct(private AiDelegationService $delegation)
    {
    }

    // ==================== 阶段一：生成提案 ====================

    /**
     * @param  Model|null $resource 更新/删除类操作的目标对象；新建类为 null
     * @param  array      $extra    附加到 payload 的非业务字段（如暂存文件路径）
     */
    public function propose(
        User $user,
        ?AiDelegationToken $token,
        string $resourceType,
        string $action,
        array $payload,
        ?string $reason = null,
        ?Model $resource = null,
        array $extra = []
    ): AiChangeProposal {
        [$before, $after] = $this->buildPreview($resourceType, $action, $payload, $resource);

        return AiChangeProposal::create([
            'user_id' => $user->id,
            'delegation_token_id' => $token?->id,
            'resource_type' => $resourceType,
            'resource_id' => $resource?->getKey(),
            'action' => $action,
            'payload' => array_merge($payload, $extra),
            'before' => $before,
            'after' => $after,
            'status' => AiChangeProposal::STATUS_PENDING,
            'reason' => $reason,
            'expires_at' => now()->addMinutes(AiChangeProposal::TTL_MINUTES),
        ]);
    }

    /**
     * 生成变更前后预览（纯计算，不落业务数据）。
     */
    private function buildPreview(string $resourceType, string $action, array $payload, ?Model $resource): array
    {
        if ($resourceType === AiChangeProposal::RESOURCE_TASK) {
            return $this->previewTask($action, $payload, $resource);
        }
        if ($resourceType === AiChangeProposal::RESOURCE_FILE) {
            return $this->previewFile($action, $payload, $resource);
        }
        if ($resourceType === AiChangeProposal::RESOURCE_DEPARTMENT_FILE) {
            return $this->previewDepartmentFile($action, $payload, $resource);
        }
        if ($resourceType === AiChangeProposal::RESOURCE_KNOWLEDGE) {
            return $this->previewKnowledge($action, $payload, $resource);
        }
        if ($resourceType === AiChangeProposal::RESOURCE_KNOWLEDGE_DOC) {
            return $this->previewKnowledgeDoc($action, $payload, $resource);
        }

        return [null, $payload];
    }

    private function previewTask(string $action, array $payload, ?Model $resource): array
    {
        if ($action === 'create') {
            return [null, $this->normalizeTaskPayload($payload)];
        }

        /** @var Task|null $resource */
        if (!$resource) {
            return [null, null];
        }

        $before = [
            'name' => $resource->name,
            'executor' => $resource->executor?->name,
            'auditor' => $resource->auditor?->name,
            'start_date' => $resource->start_date?->toDateString(),
            'end_date' => $resource->end_date?->toDateString(),
            'status' => $resource->status->label ?? null,
        ];

        if ($action === 'extension') {
            $days = (int) ($payload['days'] ?? 0);
            return [$before, array_merge($before, [
                'end_date' => $resource->end_date?->copy()->addDays($days)->toDateString(),
                'extension_days' => ($resource->extension_days ?? 0) + $days,
            ])];
        }

        return [$before, array_merge($before, $this->normalizeTaskPayload($payload, $resource))];
    }

    private function previewFile(string $action, array $payload, ?Model $resource): array
    {
        /** @var Task|null $resource */
        if (!$resource) {
            return [null, null];
        }

        $current = $resource->files()->latest()->first();
        $before = [
            'task' => $resource->name,
            'task_status' => $resource->status->label ?? null,
            'current_file' => $current ? ($current->original_name ?: $current->name) : null,
            'current_version' => $current?->version,
            'current_file_status' => $current?->status,
        ];

        if ($action === 'upload') {
            return [$before, array_merge($before, [
                'new_file' => $payload['original_name'] ?? null,
                'new_version' => 1,
                'task_status' => '待审核',
            ])];
        }
        if ($action === 'update') {
            return [$before, array_merge($before, [
                'new_file' => $payload['original_name'] ?? null,
                'new_version' => ($current?->version ?? 0) + 1,
                'task_status' => '待审核',
            ])];
        }
        if (in_array($action, ['approve', 'reject'], true)) {
            return [$before, array_merge($before, [
                'current_file_status' => $action === 'approve' ? File::STATUS_APPROVED : File::STATUS_REJECTED,
                'task_status' => $action === 'approve' ? '已完成' : '已驳回',
                'audit_reason' => $payload['reason'] ?? null,
            ])];
        }

        return [$before, null];
    }

    private function previewDepartmentFile(string $action, array $payload, ?Model $resource): array
    {
        if ($action === 'create') {
            return [null, [
                'name' => $payload['original_name'] ?? null,
                'category' => $payload['category'] ?? null,
                'visibility' => $payload['visibility'] ?? null,
            ]];
        }
        if (!$resource) {
            return [null, null];
        }
        /** @var DepartmentFile $resource */
        $before = [
            'name' => $resource->original_name ?: $resource->name,
            'category' => $resource->getCategoryNameAttribute(),
            'visibility' => $resource->getVisibilityNameAttribute(),
            'version' => $resource->version,
        ];
        if ($action === 'delete') {
            return [$before, null];
        }

        $after = $before;
        if (!empty($payload['original_name'])) {
            $after['name'] = $payload['original_name'];
        }
        if (isset($payload['category'])) {
            $after['category'] = DepartmentFile::categoryOptions()[$payload['category']] ?? $payload['category'];
        }
        if (isset($payload['visibility'])) {
            $after['visibility'] = DepartmentFile::visibilityOptions()[$payload['visibility']] ?? $payload['visibility'];
        }

        return [$before, $after];
    }

    private function previewKnowledge(string $action, array $payload, ?Model $resource): array
    {
        if ($action === 'create') {
            return [null, [
                'title' => $payload['title'] ?? null,
                'desc' => $payload['desc'] ?? null,
                'is_share' => (bool) ($payload['is_share'] ?? false),
            ]];
        }
        if (!$resource) {
            return [null, null];
        }
        /** @var Knowledge $resource */
        $before = [
            'title' => $resource->title,
            'desc' => $resource->desc,
            'is_share' => (bool) $resource->is_share,
        ];
        if ($action === 'delete') {
            return [$before, null];
        }

        return [$before, array_merge($before, array_intersect_key($payload, $before))];
    }

    private function previewKnowledgeDoc(string $action, array $payload, ?Model $resource): array
    {
        if ($action === 'create') {
            return [null, [
                'title' => $payload['title'] ?? null,
                'type' => KnowledgeDoc::typeLabel((int) ($payload['type'] ?? KnowledgeDoc::TYPE_DOC)),
                'pid' => $payload['pid'] ?? 0,
                'md_content' => $this->excerpt($payload['md_content'] ?? null),
            ]];
        }
        if (!$resource) {
            return [null, null];
        }
        /** @var KnowledgeDoc $resource */
        $before = [
            'title' => $resource->title,
            'type' => KnowledgeDoc::typeLabel((int) $resource->type),
            'md_content' => $this->excerpt($resource->md_content),
        ];
        if ($action === 'delete') {
            return [$before, null];
        }

        return [$before, array_merge($before, array_filter([
            'title' => $payload['title'] ?? null,
            'md_content' => $this->excerpt($payload['md_content'] ?? null),
        ], fn ($v) => $v !== null))];
    }

    private function normalizeTaskPayload(array $payload, ?Task $task = null): array
    {
        $out = [];
        foreach (['name', 'start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $payload)) {
                $out[$field] = $payload[$field];
            }
        }
        if (!empty($payload['executor_id'])) {
            $executor = User::find((int) $payload['executor_id']);
            $out['executor'] = $executor?->name;
        }
        if (!empty($payload['auditor_id'])) {
            $auditor = User::find((int) $payload['auditor_id']);
            $out['auditor'] = $auditor?->name;
        }
        if (!empty($payload['task_type_id'])) {
            $taskType = TaskType::find((int) $payload['task_type_id']);
            $out['task_type'] = $taskType?->name;
        }
        return $out;
    }

    private function excerpt(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        return Str::limit($text, 500);
    }

    /**
     * 补全「新建任务」必填项。
     *
     * tasks 表的 department_id / executor_id / auditor_id / start_date / end_date 均为 NOT NULL，
     * 因此这里在「生成提案」阶段就把缺省值补齐，让用户看到的 diff 即最终落库值。
     */
    public function normalizeTaskCreate(array $payload, Project $project): array
    {
        $executorId = (int) ($payload['executor_id'] ?? 0);
        $executor = $executorId ? User::find($executorId) : null;

        if (!$executor) {
            throw new \InvalidArgumentException('新建任务必须指定 executor_id（执行人）。');
        }
        if (empty($payload['auditor_id'])) {
            throw new \InvalidArgumentException('新建任务必须指定 auditor_id（审核人），且不能与执行人相同。');
        }
        if ((int) $payload['auditor_id'] === $executor->id) {
            throw new \InvalidArgumentException('审核人不能与执行人相同。');
        }

        $payload['executor_id'] = $executor->id;
        $payload['auditor_id'] = (int) $payload['auditor_id'];
        $payload['department_id'] = (int) ($payload['department_id'] ?? $executor->department_id ?? 0);

        if (empty($payload['start_date'])) {
            $payload['start_date'] = optional($project->start_date)->toDateString() ?? now()->toDateString();
        }
        if (empty($payload['end_date'])) {
            $payload['end_date'] = optional($project->end_date)->toDateString()
                ?? now()->addDays(7)->toDateString();
        }

        return $payload;
    }

    // ==================== 阶段二：确认执行 ====================

    /**
     * 确认并真正执行变更。只有授权人本人（或管理员）可调用。
     */
    public function confirm(AiChangeProposal $proposal, User $approver, ?string $note = null): array
    {
        if (!$proposal->isPending()) {
            return ['ok' => false, 'message' => '提案已处理，不可重复执行。'];
        }
        if ($proposal->isExpired()) {
            $proposal->update(['status' => AiChangeProposal::STATUS_EXPIRED]);
            return ['ok' => false, 'message' => '提案已过期，请让 AI 重新发起。'];
        }
        if (!$this->delegation->canResolveProposal($approver, $proposal)) {
            return ['ok' => false, 'message' => '只有授权人本人或管理员可确认该提案。'];
        }

        try {
            $result = DB::transaction(fn () => $this->execute($proposal, $approver));
        } catch (\Throwable $e) {
            report($e);
            $proposal->update([
                'status' => AiChangeProposal::STATUS_FAILED,
                'resolved_at' => now(),
                'resolve_note' => $note,
                'result' => ['error' => $e->getMessage()],
            ]);
            return ['ok' => false, 'message' => '执行失败：' . $e->getMessage()];
        }

        $proposal->update([
            'status' => AiChangeProposal::STATUS_CONFIRMED,
            'resolved_at' => now(),
            'resolve_note' => $note,
            'result' => $result,
        ]);

        $this->log($proposal, $approver, '确认执行 AI 变更提案');

        return ['ok' => true, 'result' => $result];
    }

    public function reject(AiChangeProposal $proposal, User $approver, ?string $note = null): array
    {
        if (!$proposal->isPending()) {
            return ['ok' => false, 'message' => '提案已处理。'];
        }
        if (!$this->delegation->canResolveProposal($approver, $proposal)) {
            return ['ok' => false, 'message' => '只有授权人本人或管理员可驳回该提案。'];
        }

        // 清理为本次提案暂存的上传文件
        $this->discardStaging($proposal);

        $proposal->update([
            'status' => AiChangeProposal::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolve_note' => $note,
        ]);

        $this->log($proposal, $approver, '驳回 AI 变更提案');

        return ['ok' => true];
    }

    /**
     * 真正的写入逻辑 —— 唯一改动业务数据的地方。
     */
    private function execute(AiChangeProposal $proposal, User $approver): array
    {
        $payload = (array) $proposal->payload;

        return match ($proposal->resource_type) {
            AiChangeProposal::RESOURCE_TASK => $this->executeTask($proposal, $payload, $approver),
            AiChangeProposal::RESOURCE_FILE => $this->executeFile($proposal, $payload, $approver),
            AiChangeProposal::RESOURCE_DEPARTMENT_FILE => $this->executeDepartmentFile($proposal, $payload, $approver),
            AiChangeProposal::RESOURCE_KNOWLEDGE => $this->executeKnowledge($proposal, $payload, $approver),
            AiChangeProposal::RESOURCE_KNOWLEDGE_DOC => $this->executeKnowledgeDoc($proposal, $payload, $approver),
            default => throw new \RuntimeException('未知的变更资源类型：' . $proposal->resource_type),
        };
    }

    // ---------- 任务 ----------

    private function executeTask(AiChangeProposal $proposal, array $payload, User $approver): array
    {
        if ($proposal->action === 'create') {
            $task = Task::create(array_merge($payload, [
                'status' => \App\States\Task\Pending::class,
            ]));
            return ['task_id' => $task->id];
        }

        $task = Task::findOrFail($proposal->resource_id);

        if ($proposal->action === 'update') {
            $data = array_intersect_key($payload, array_flip([
                'name', 'department_id', 'task_type_id', 'executor_id', 'auditor_id', 'start_date', 'end_date',
            ]));
            $task->update($data);
            return ['task_id' => $task->id, 'updated_fields' => array_keys($data)];
        }

        if ($proposal->action === 'extension') {
            $task->extendDays((int) $payload['days']);
            return ['task_id' => $task->id, 'new_end_date' => $task->end_date?->toDateString()];
        }

        throw new \RuntimeException('不支持的任务动作：' . $proposal->action);
    }

    // ---------- 任务文件 ----------

    private function executeFile(AiChangeProposal $proposal, array $payload, User $approver): array
    {
        $task = Task::findOrFail($proposal->resource_id);
        $taskService = app(TaskService::class);

        if (in_array($proposal->action, ['upload', 'update'], true)) {
            $uploaded = $this->stagingToUploadedFile($payload);
            if ($proposal->action === 'upload') {
                $res = $taskService->storeFile($task, $uploaded, $approver->id);
            } else {
                $taskService->updateFile($task, $uploaded, $approver->id);
                $res = ['file' => $task->files()->latest()->first()];
            }
            $this->discardStaging($proposal);

            return ['file_id' => $res['file']->id ?? null, 'version' => $res['file']->version ?? null];
        }

        if ($proposal->action === 'approve') {
            $taskService->approve($task, $payload['reason'] ?? null, $approver->id);
            return ['task_id' => $task->id, 'status' => 'approved'];
        }
        if ($proposal->action === 'reject') {
            $taskService->reject($task, $payload['reason'] ?? null, $approver->id);
            return ['task_id' => $task->id, 'status' => 'rejected'];
        }

        throw new \RuntimeException('不支持的文件动作：' . $proposal->action);
    }

    // ---------- 支援文件 ----------

    private function executeDepartmentFile(AiChangeProposal $proposal, array $payload, User $approver): array
    {
        if ($proposal->action === 'create') {
            $uploaded = $this->stagingToUploadedFile($payload);
            $fileName = uniqid() . '_v1.' . $uploaded->getClientOriginalExtension();
            $path = $uploaded->storeAs('department-' . $approver->department_id, $fileName, 'file_repository');

            $file = DepartmentFile::create([
                'department_id' => $approver->department_id,
                'uploader_id' => $approver->id,
                'name' => $fileName,
                'original_name' => $uploaded->getClientOriginalName(),
                'path' => $path,
                'size' => $uploaded->getSize(),
                'mime_type' => $uploaded->getMimeType(),
                'category' => $payload['category'],
                'visibility' => $payload['visibility'],
                'version' => 1,
            ]);
            $this->discardStaging($proposal);

            return ['department_file_id' => $file->id];
        }

        $file = DepartmentFile::findOrFail($proposal->resource_id);

        if ($proposal->action === 'update') {
            // 仅改元数据（分类 / 可见范围）时无需重新上传文件
            if (empty($payload['staging_path'])) {
                $meta = array_intersect_key($payload, array_flip(['category', 'visibility']));
                $file->update($meta);

                return ['department_file_id' => $file->id, 'updated_fields' => array_keys($meta)];
            }

            $uploaded = $this->stagingToUploadedFile($payload);
            $newVersion = $file->version + 1;
            $fileName = uniqid() . "_v{$newVersion}." . $uploaded->getClientOriginalExtension();
            $path = $uploaded->storeAs('department-' . $file->department_id, $fileName, 'file_repository');
            $oldPath = $file->path;

            $file->update([
                'name' => $fileName,
                'original_name' => $uploaded->getClientOriginalName(),
                'path' => $path,
                'size' => $uploaded->getSize(),
                'mime_type' => $uploaded->getMimeType(),
                'version' => $newVersion,
            ]);
            if (!empty($oldPath) && $oldPath !== $path) {
                Storage::disk('file_repository')->delete($oldPath);
            }
            $this->discardStaging($proposal);

            return ['department_file_id' => $file->id, 'version' => $newVersion];
        }

        if ($proposal->action === 'delete') {
            $file->delete();
            return ['department_file_id' => $file->id, 'deleted' => true];
        }

        throw new \RuntimeException('不支持的支援文件动作：' . $proposal->action);
    }

    // ---------- 知识库 ----------

    private function executeKnowledge(AiChangeProposal $proposal, array $payload, User $approver): array
    {
        if ($proposal->action === 'create') {
            $knowledge = Knowledge::create([
                'admin_id' => $approver->id,
                'cate_id' => $payload['cate_id'] ?? null,
                'title' => $payload['title'],
                'desc' => $payload['desc'] ?? null,
                'is_share' => (bool) ($payload['is_share'] ?? false),
            ]);
            return ['knowledge_id' => $knowledge->id];
        }

        $knowledge = Knowledge::findOrFail($proposal->resource_id);

        if ($proposal->action === 'update') {
            $knowledge->update(array_intersect_key($payload, array_flip(['title', 'desc', 'cate_id', 'is_share'])));
            return ['knowledge_id' => $knowledge->id];
        }
        if ($proposal->action === 'delete') {
            $knowledge->delete();
            return ['knowledge_id' => $knowledge->id, 'deleted' => true];
        }

        throw new \RuntimeException('不支持的知识库动作：' . $proposal->action);
    }

    private function executeKnowledgeDoc(AiChangeProposal $proposal, array $payload, User $approver): array
    {
        if ($proposal->action === 'create') {
            $doc = KnowledgeDoc::create([
                'knowledge_id' => $payload['knowledge_id'],
                'pid' => $payload['pid'] ?? 0,
                'type' => $payload['type'] ?? KnowledgeDoc::TYPE_DOC,
                'title' => $payload['title'],
                'md_content' => $payload['md_content'] ?? null,
                'link' => $payload['link'] ?? null,
            ]);
            return ['doc_id' => $doc->id];
        }

        $doc = KnowledgeDoc::findOrFail($proposal->resource_id);

        if ($proposal->action === 'update') {
            $doc->update(array_intersect_key($payload, array_flip(['title', 'md_content', 'link', 'type', 'pid', 'sort'])));
            return ['doc_id' => $doc->id];
        }
        if ($proposal->action === 'delete') {
            $doc->delete();
            return ['doc_id' => $doc->id, 'deleted' => true];
        }

        throw new \RuntimeException('不支持的知识库文档动作：' . $proposal->action);
    }

    // ==================== 暂存文件 ====================

    /**
     * AI 上传的文件先落暂存盘，确认后才由业务 Service 正式入库；驳回则丢弃。
     */
    public function stageUploadedFile(UploadedFile $file): array
    {
        $name = uniqid('ai_stage_', true) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs(date('Ymd'), $name, ['disk' => self::STAGING_DISK]);

        return [
            'staging_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    private function stagingToUploadedFile(array $payload): UploadedFile
    {
        $disk = Storage::disk(self::STAGING_DISK);
        $relative = $payload['staging_path'] ?? null;

        if (!$relative || !$disk->exists($relative)) {
            throw new \RuntimeException('暂存的上传文件已失效，请让 AI 重新上传。');
        }

        return new UploadedFile(
            $disk->path($relative),
            $payload['original_name'] ?? basename($relative),
            $payload['mime_type'] ?? null,
            null,
            true // test mode：文件并非来自本次 HTTP 上传
        );
    }

    public function discardStaging(AiChangeProposal $proposal): void
    {
        $relative = ($proposal->payload['staging_path'] ?? null);
        if ($relative) {
            Storage::disk(self::STAGING_DISK)->delete($relative);
        }
    }

    // ==================== 辅助 ====================

    public function expireStale(): int
    {
        $stale = AiChangeProposal::where('status', AiChangeProposal::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $proposal) {
            $this->discardStaging($proposal);
            $proposal->update(['status' => AiChangeProposal::STATUS_EXPIRED]);
        }

        return $stale->count();
    }

    private function log(AiChangeProposal $proposal, User $approver, string $action): void
    {
        OperationLog::create([
            'user_id' => $approver->id,
            'ip' => request()->ip(),
            'module' => 'ai_delegation',
            'action' => $action,
            'content' => sprintf(
                '[#%d] %s %s（%s）',
                $proposal->id,
                $proposal->resourceLabel(),
                $proposal->actionLabel(),
                $proposal->resource_id ? 'ID:' . $proposal->resource_id : '新增'
            ),
        ]);
    }
}
