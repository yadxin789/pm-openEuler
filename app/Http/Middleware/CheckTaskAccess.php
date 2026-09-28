<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Task;

class CheckTaskAccess
{
    public function handle(Request $request, Closure $next, string $action = 'view')
    {
        $user = $request->user();

        if (!$user) {
            abort(401, '请先登录');
        }

        $task = $request->route('task');

        if (!$task instanceof Task) {
            abort(404, '任务不存在');
        }

        // 管理员可访问所有
        if ($user->isAdmin()) {
            return $next($request);
        }

        $project = $task->project;

        // 项目创建人可访问
        if ($user->id === $project->creator_id) {
            return $next($request);
        }

        // 根据操作类型检查权限
        switch ($action) {
            case 'upload':
            case 'extension':
                if ($user->id !== $task->executor_id) {
                    abort(403, '只有任务执行人才能执行此操作');
                }
                break;
            case 'audit':
                if ($user->id !== $task->auditor_id) {
                    abort(403, '只有审核人才能执行此操作');
                }
                break;
            case 'update-file':
                if ($user->id !== $task->executor_id
                    && $user->id !== $project->creator_id
                    && !$user->isProjectManager()) {
                    abort(403, '您没有权限更新此文件');
                }
                break;
            case 'view':
            default:
                if ($user->id !== $task->executor_id && $user->id !== $task->auditor_id) {
                    abort(403, '您没有权限查看此任务');
                }
                break;
        }

        return $next($request);
    }
}
