<?php

namespace App\Helpers;

use App\Models\OperationLog;
use Illuminate\Support\Facades\Auth;

class OperationLogHelper
{
    /**
     * 记录一条操作日志
     *
     * @param string $module   模块名称（如：认证、用户管理、部门管理等）
     * @param string $action   操作动作（如：登录、退出、创建用户等）
     * @param string $content  操作内容描述
     * @param int|null $userId 操作人ID（默认当前登录用户）
     * @param string|null $ip  IP地址（默认当前请求IP）
     */
    public static function log(
        string $module,
        string $action,
        string $content = '',
        ?int $userId = null,
        ?string $ip = null
    ): void {
        $userId = $userId ?? Auth::id();
        $ip = $ip ?? request()->ip();

        OperationLog::create([
            'user_id' => $userId,
            'ip' => $ip,
            'module' => $module,
            'action' => $action,
            'content' => $content,
        ]);
    }
}
