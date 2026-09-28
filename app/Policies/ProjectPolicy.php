<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * 查看项目详情 — 仅项目参与者可查看
     */
    public function view(User $user, Project $project): bool
    {
        if ($user->isAdmin() || $user->isProjectManager()) {
            return true;
        }
        if ($user->id === $project->creator_id) {
            return true;
        }
        return $project->tasks()
            ->where(function ($q) use ($user) {
                $q->where('executor_id', $user->id)
                  ->orWhere('auditor_id', $user->id);
            })
            ->exists();
    }

    /**
     * 创建项目 — 项目发起人或管理员
     */
    public function create(User $user): bool
    {
        return $user->isProjectCreator() || $user->isAdmin();
    }

    /**
     * 编辑/更新项目 — 项目创建人或管理员
     */
    public function update(User $user, Project $project): bool
    {
        return $user->id === $project->creator_id || $user->isAdmin();
    }

    /**
     * 删除项目 — 仅项目创建人或管理员
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->id === $project->creator_id || $user->isAdmin();
    }

    /**
     * 暂停项目 — 使用 Project 模型的统一判断（角色 + 状态：必须是 InProgress/Overdue）
     */
    public function pause(User $user, Project $project): bool
    {
        return $project->canPause($user->id);
    }

    /**
     * 恢复项目 — 使用 Project 模型的统一判断（角色 + 状态：必须是 Suspended）
     */
    public function resume(User $user, Project $project): bool
    {
        return $project->canResume($user->id);
    }

    /**
     * 变更项目 — 使用 Project 模型的统一判断（角色 + 状态：非终态）
     */
    public function change(User $user, Project $project): bool
    {
        return $project->canChange($user->id);
    }
}