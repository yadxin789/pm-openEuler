<?php

use App\Http\Controllers\Api\AiApiController;
use App\Http\Controllers\Api\AiWriteApiController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI 数据 API 路由
|--------------------------------------------------------------------------
| 供 AI（含外部 Agent）读取 / 变更 PM 系统数据。
|
| 认证：X-AI-Key      —— 系统级客户端密钥（.env 的 AI_API_KEY）
| 授权：X-AI-Token    —— 用户委托令牌（用户在「AI 授权中心」签发）
|
| 核心原则：谁对这份数据拥有读 / 写 / 下载权限，谁就可以授权 AI 读 / 写 / 下载。
|   携带委托令牌后，本次请求以该用户身份执行，可见范围与操作权限完全等同其本人。
|   一切写操作均为两阶段：AI 只能提交提案，经授权人确认后才真正落库。
*/

// 限流：优先按委托令牌维度，无令牌时退化为 IP 维度
RateLimiter::for('ai-api', function (Request $request) {
    $default = (int) config('pm.ai_api.rate_limit_per_minute', 120);

    $key = $request->header('X-AI-Token')
        ? 'ai-token:' . substr(hash('sha256', $request->header('X-AI-Token')), 0, 16)
        : 'ai-ip:' . $request->ip();

    $limit = $default;
    if ($token = $request->attributes->get('ai.token')) {
        $limit = $token->rate_limit_per_minute ?: $default;
    }

    return Limit::perMinute($limit)->by($key);
});

Route::prefix('ai')->middleware(['ai.api.auth', 'throttle:ai-api'])->name('ai.api.')->group(function () {
    // ---------- 只读 ----------
    Route::get('/meta', [AiApiController::class, 'meta'])->name('meta');
    Route::get('/overview', [AiApiController::class, 'overview'])->name('overview');

    Route::get('/projects', [AiApiController::class, 'projects'])->name('projects');
    Route::get('/projects/{id}', [AiApiController::class, 'projectShow'])->name('projects.show');

    Route::get('/tasks', [AiApiController::class, 'tasks'])->name('tasks');
    Route::get('/tasks/{id}', [AiApiController::class, 'taskShow'])->name('tasks.show');

    Route::get('/users', [AiApiController::class, 'users'])->name('users');
    Route::get('/departments', [AiApiController::class, 'departments'])->name('departments');

    Route::get('/knowledge-categories', [AiApiController::class, 'knowledgeCategories'])->name('knowledge-categories');
    Route::get('/knowledge', [AiApiController::class, 'knowledge'])->name('knowledge');
    Route::get('/knowledge/{id}', [AiApiController::class, 'knowledgeShow'])->name('knowledge.show');

    Route::get('/files', [AiApiController::class, 'files'])->name('files');
    Route::get('/files/{id}', [AiApiController::class, 'fileShow'])->name('files.show');
    Route::get('/files/{id}/versions', [AiApiController::class, 'fileVersions'])->name('files.versions');
    Route::get('/files/{id}/content', [AiApiController::class, 'fileContent'])->name('files.content');
    Route::get('/files/{id}/download', [AiApiController::class, 'fileDownload'])->name('files.download');

    Route::get('/department-files', [AiApiController::class, 'departmentFiles'])->name('department-files');
    Route::get('/department-files/{id}/download', [AiApiController::class, 'departmentFileDownload'])->name('department-files.download');

    Route::get('/templates', [AiApiController::class, 'templates'])->name('templates');
    Route::get('/templates/{id}', [AiApiController::class, 'templateShow'])->name('templates.show');

    Route::get('/todos', [AiApiController::class, 'todos'])->name('todos');

    // ---------- 写入（两阶段：提案 -> 人工确认）----------
    Route::prefix('write')->name('write.')->group(function () {
        // 提案查询
        Route::get('/proposals', [AiWriteApiController::class, 'proposals'])->name('proposals');
        Route::get('/proposals/{id}', [AiWriteApiController::class, 'proposalShow'])->name('proposals.show');

        // 任务
        Route::post('/tasks', [AiWriteApiController::class, 'storeTask'])->name('tasks.store');
        Route::put('/tasks/{id}', [AiWriteApiController::class, 'updateTask'])->name('tasks.update');
        Route::post('/tasks/{id}/extension', [AiWriteApiController::class, 'applyExtension'])->name('tasks.extension');

        // 任务文件
        Route::post('/tasks/{id}/upload', [AiWriteApiController::class, 'uploadTaskFile'])->name('tasks.upload');
        Route::post('/tasks/{id}/update-file', [AiWriteApiController::class, 'updateTaskFile'])->name('tasks.update-file');
        Route::post('/tasks/{id}/audit', [AiWriteApiController::class, 'auditTask'])->name('tasks.audit');

        // 支援文件（部门资料文件）
        Route::post('/department-files', [AiWriteApiController::class, 'storeDepartmentFile'])->name('department-files.store');
        Route::post('/department-files/{id}', [AiWriteApiController::class, 'updateDepartmentFile'])->name('department-files.update');
        Route::delete('/department-files/{id}', [AiWriteApiController::class, 'destroyDepartmentFile'])->name('department-files.destroy');

        // 知识库
        Route::post('/knowledge', [AiWriteApiController::class, 'storeKnowledge'])->name('knowledge.store');
        Route::put('/knowledge/{id}', [AiWriteApiController::class, 'updateKnowledge'])->name('knowledge.update');
        Route::delete('/knowledge/{id}', [AiWriteApiController::class, 'destroyKnowledge'])->name('knowledge.destroy');

        // 知识库文档
        Route::post('/knowledge/{id}/docs', [AiWriteApiController::class, 'storeKnowledgeDoc'])->name('knowledge-docs.store');
        Route::put('/knowledge-docs/{docId}', [AiWriteApiController::class, 'updateKnowledgeDoc'])->name('knowledge-docs.update');
        Route::delete('/knowledge-docs/{docId}', [AiWriteApiController::class, 'destroyKnowledgeDoc'])->name('knowledge-docs.destroy');
    });
});
