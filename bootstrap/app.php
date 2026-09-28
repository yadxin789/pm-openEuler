<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'project.access' => \App\Http\Middleware\CheckProjectAccess::class,
            'task.access' => \App\Http\Middleware\CheckTaskAccess::class,
        ]);

        // 登录速率限制
        $middleware->throttleWithRedis('login');

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
