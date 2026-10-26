<?php

namespace App\Services;

use App\Models\AiChangeProposal;
use App\Models\AiDelegationToken;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 委托鉴权服务 —— AI API 的「单一可信权限源」。
 *
 * 设计原则：
 *   谁对这份数据拥有读 / 写 / 下载的权限，谁就可以授权 AI 读 / 写 / 下载。
 * 因此这里不定义任何新权限，只把业务模型自身已有的鉴权方法
 * （Task::canUploadFile / File::canDownload / Knowledge::isEditableBy / DepartmentFile::canAccess ...）
 * 以「委托人身份」重新跑一遍。
 */
class AiDelegationService
{
    public function __construct(private Request $request)
    {
    }

    // ==================== 身份 ====================

    public function token(): ?AiDelegationToken
    {
        return $this->request->attributes->get('ai.token');
    }

    public function user(): ?User
    {
        return $this->request->attributes->get('ai.user');
    }

    /**
     * 取委托人，缺失直接 401。
     * 用于「必须明确授权人」的接口：文件下载、一切写操作。
     */
    public function requireUser(): User
    {
        $user = $this->user();
        if (!$user) {
            abort(response()->json([
                'error' => '未授权：该操作必须由用户显式授权，请在请求头携带 X-AI-Token（用户委托令牌）。',
            ], Response::HTTP_UNAUTHORIZED));
        }
        return $user;
    }

    /**
     * 校验委托令牌是否包含某作用域。
     *
     * 未携带令牌的匿名调用不做 scope 校验：是否允许匿名由中间件按
     * config('pm.ai_api.allow_anonymous_read') 控制，可见范围另行收敛。
     * 写接口在此之前已由 requireUser() 保证令牌存在。
     */
    public function requireScope(string $scope): void
    {
        $token = $this->token();
        if ($token === null) {
            return;
        }
        if (!$token->hasScope($scope)) {
            abort(response()->json([
                'error' => "未授权：当前委托令牌未授予 `{$scope}` 权限。",
                'required_scope' => $scope,
                'granted_scopes' => $token?->scopes ?? [],
            ], Response::HTTP_FORBIDDEN));
        }
    }

    public function hasScope(string $scope): bool
    {
        return (bool) $this->token()?->hasScope($scope);
    }

    // ==================== 任务 ====================

    public function canReadTask(User $user, Task $task): bool
    {
        // 与 web 侧一致：项目成员、创建人、项目管理者、管理员可见
        if ($user->isAdmin() || $user->isProjectManager()) {
            return true;
        }
        $project = $task->project;
        if (!$project) {
            return false;
        }
        if ($project->creator_id === $user->id) {
            return true;
        }
        return $user->id === $task->executor_id
            || $user->id === $task->auditor_id
            || (int) $user->department_id === (int) $task->department_id;
    }

    public function canUploadFile(User $user, Task $task): bool
    {
        return $task->canUploadFile($user->id);
    }

    public function canUpdateFile(User $user, Task $task): bool
    {
        return $task->canUpdateFile($user->id);
    }

    public function canAudit(User $user, Task $task): bool
    {
        return $task->canAudit($user->id);
    }

    public function canApplyExtension(User $user, Task $task): bool
    {
        return $task->canApplyExtension($user->id);
    }

    /**
     * 是否能编辑任务本身（改名称 / 执行人 / 审核人 / 日期）。
     * 对齐项目管理侧：管理员、项目创建人、执行人、审核人。
     */
    public function canManageTask(User $user, Task $task): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return (int) $task->executor_id === $user->id
            || (int) $task->auditor_id === $user->id
            || (int) $task->project?->creator_id === $user->id;
    }

    /**
     * 是否能新建任务（目前仅项目创建人与管理员，与 Web 侧项目内建任务一致）。
     */
    public function canCreateTask(User $user, Project $project): bool
    {
        return $user->isAdmin() || (int) $project->creator_id === $user->id;
    }

    // ==================== 任务文件 ====================

    /**
     * 能否读取任务文件元数据。
     */
    public function canReadFile(User $user, File $file): bool
    {
        if ($file->task_id) {
            return $this->canReadTask($user, $file->task);
        }
        return $user->isAdmin();
    }

    /**
     * 能否下载任务文件正文 —— 直接复用 File 自身的下载权限判定。
     */
    public function canDownloadFile(User $user, File $file): bool
    {
        return $file->canDownload($user->id);
    }

    public function canPreviewFile(User $user, File $file): bool
    {
        return $file->canPreview($user->id);
    }

    // ==================== 支援文件（部门资料文件）====================

    public function canReadDepartmentFile(User $user, DepartmentFile $file): bool
    {
        return $file->canAccess($user->id);
    }

    /**
     * 支援文件的写权限：仅管理员或上传人本人（与 FileController::deleteDepartmentFile 一致）。
     */
    public function canWriteDepartmentFile(User $user, ?DepartmentFile $file = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return $file !== null && $file->uploader_id === $user->id;
    }

    // ==================== 知识库 ====================

    public function canReadKnowledge(User $user, Knowledge $knowledge): bool
    {
        return $knowledge->isVisibleTo($user->id) || $user->isAdmin();
    }

    public function canWriteKnowledge(User $user, Knowledge $knowledge): bool
    {
        return $knowledge->isEditableBy($user->id);
    }

    public function canWriteKnowledgeDoc(User $user, KnowledgeDoc $doc): bool
    {
        return $doc->knowledge?->isEditableBy($user->id) ?? false;
    }

    // ==================== 变更提案 ====================

    /**
     * 提案只能由签发者本人审批。
     */
    public function canResolveProposal(User $user, AiChangeProposal $proposal): bool
    {
        return $proposal->user_id === $user->id || $user->isAdmin();
    }
}
