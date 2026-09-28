<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Task;

class CheckProjectAccess
{
    public function handle(Request $request, Closure $next, string $action = 'view')
    {
        $user = $request->user();

        if (!$user) {
            abort(401, '请先登录');
        }

        // 管理员和项目总经理可以访问所有项目
        if ($user->isAdmin() || $user->isProjectManager()) {
            return $next($request);
        }

        $project = $request->route('project');

        if (!$project instanceof Project) {
            abort(404, '项目不存在');
        }

        // 项目创建人拥有所有权限
        if ($user->id === $project->creator_id) {
            return $next($request);
        }

        // 检查是否为项目参与者
        $isParticipant = $project->tasks()
            ->where(function ($q) use ($user) {
                $q->where('executor_id', $user->id)
                  ->orWhere('auditor_id', $user->id);
            })
            ->exists();

        if (!$isParticipant) {
            abort(403, '您没有权限访问该项目');
        }

        // 对于编辑/删除等操作，仅创建人和管理员可执行
        if (in_array($action, ['edit', 'delete', 'change', 'pause'])) {
            abort(403, '您没有权限执行此操作');
        }

        return $next($request);
    }
}
