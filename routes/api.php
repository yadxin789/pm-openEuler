<?php

use App\Http\Controllers\Api\AiApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI 数据 API 路由
|--------------------------------------------------------------------------
| 供 AI（含外部 Agent）全面调取 PM 系统数据。
| 认证：请求头 X-AI-Key 或 query 参数 api_key，密钥 = .env 的 AI_API_KEY。
*/

Route::prefix('ai')->middleware('ai.api.auth')->name('ai.api.')->group(function () {
    Route::get('/meta', [AiApiController::class, 'meta'])->name('meta');
    Route::get('/overview', [AiApiController::class, 'overview'])->name('overview');

    Route::get('/projects', [AiApiController::class, 'projects'])->name('projects');
    Route::get('/projects/{id}', [AiApiController::class, 'projectShow'])->name('projects.show');

    Route::get('/tasks', [AiApiController::class, 'tasks'])->name('tasks');
    Route::get('/users', [AiApiController::class, 'users'])->name('users');
    Route::get('/departments', [AiApiController::class, 'departments'])->name('departments');
    Route::get('/knowledge', [AiApiController::class, 'knowledge'])->name('knowledge');
    Route::get('/files', [AiApiController::class, 'files'])->name('files');
    Route::get('/todos', [AiApiController::class, 'todos'])->name('todos');
    Route::get('/templates', [AiApiController::class, 'templates'])->name('templates');
});
