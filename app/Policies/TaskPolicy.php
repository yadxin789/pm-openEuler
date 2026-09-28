<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * 查看任务详情 — 项目参与者
     */
    public function view(User $user, Task $task): bool
    {
        if ($user->isAdmin() || $user->isProjectManager()) {
            return true;
        }
        if ($user->id === $task->project->creator_id) {
            return true;
        }
        return $user->id === $task->executor_id || $user->id === $task->auditor_id;
    }

    /**
     * 上传文件 — 调用 Task 模型统一判断（含角色+状态+项目激活检查）
     */
    public function uploadFile(User $user, Task $task): bool
    {
        return $task->canUploadFile($user->id);
    }

    /**
     * 审核文件 — 调用 Task 模型统一判断（含角色+状态+必须有pending文件）
     */
    public function audit(User $user, Task $task): bool
    {
        return $task->canAudit($user->id);
    }

    /**
     * 申请延期 — 调用 Task 模型统一判断（含角色+状态+项目激活检查）
     */
    public function applyExtension(User $user, Task $task): bool
    {
        return $task->canApplyExtension($user->id);
    }

    /**
     * 更新文件 — 调用 Task 模型统一判断（含角色+状态+必须有approved文件）
     */
    public function updateFile(User $user, Task $task): bool
    {
        return $task->canUpdateFile($user->id);
    }
}