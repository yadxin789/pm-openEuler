<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'project.access' => \App\Http\Middleware\CheckProjectAccess::class,
            'task.access' => \App\Http\Middleware\CheckTaskAccess::class,
            'ai.api.auth' => \App\Http\Middleware\AiApiAuth::class,
        ]);

        // 说明：本环境未安装 Redis（PHP 扩展缺失），不能使用 throttleWithRedis，
        //      否则所有 throttle 路由（AI 提问 / 文档生成）会因「Class Redis not found」报 500。
        //      限流统一走 RateLimiter + Cache（当前 CACHE_STORE=database，cache / cache_locks 表已就绪）；
        //      登录失败次数限制由 AuthController 内 RateLimiter 自行控制。

        // OnlyOffice 服务器保存回调（无登录态，doc_key 校验，需绕过 CSRF）
        $middleware->validateCsrfTokens(except: [
            'collab/callback/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 生产环境不暴露异常详情
        if (!app()->isLocal()) {
            $exceptions->dontReport([]);
        }
    })->create();
