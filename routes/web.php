<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\ProjectTemplateController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PrivateMessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\WecomAuthController;
use App\Http\Controllers\WecomSettingsController;
use App\Http\Controllers\WecomCallbackController;
use App\Http\Controllers\WecomFileController;
use App\Http\Controllers\UserWecomController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\CollabDocumentController;
use App\Http\Controllers\AdminTrashController;
use App\Http\Controllers\MyTrashController;
use App\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WelcomeController::class, 'index'])->name('home');

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// 企业微信 OAuth 路由（公开）
Route::prefix('wecom')->name('wecom.')->group(function () {
    Route::get('/auth', [WecomAuthController::class, 'redirect'])->name('auth');
    Route::get('/callback', [WecomAuthController::class, 'callback'])->name('callback');
    Route::post('/callback/event', [WecomCallbackController::class, 'handle'])->name('event-callback');
});

// 认证路由
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 个人回收站：每个人管理「自己删除的 / 自己创建的」内容（管理员只有统一回收站入口）
    Route::get('/my-trash', [MyTrashController::class, 'index'])->name('my-trash');
    Route::post('/my-trash/{type}/{id}/restore', [MyTrashController::class, 'restore'])->name('my-trash.restore')->whereNumber('id');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        
        Route::get('/users/deleted', [AdminController::class, 'deletedUsers'])->name('users.deleted');
        Route::post('/users/restore/{user}', [AdminController::class, 'restoreUser'])->name('users.restore');
        Route::delete('/users/force-delete/{user}', [AdminController::class, 'forceDeleteUser'])->name('users.force-delete');

        // 回收站：全系统软删除内容的恢复与彻底删除（仅管理员）
        Route::get('/collab-trash', [AdminTrashController::class, 'index'])->name('trash');
        // 批量路由必须注册在 /{id} 之前，否则 restore-batch 会被当作 id 匹配
        Route::post('/collab-trash/{type}/restore-batch', [AdminTrashController::class, 'restoreBatch'])->name('trash.restore-batch');
        Route::delete('/collab-trash/{type}/force-delete-batch', [AdminTrashController::class, 'forceDeleteBatch'])->name('trash.force-delete-batch');
        Route::post('/collab-trash/{type}/{id}/restore', [AdminTrashController::class, 'restore'])->name('trash.restore')->whereNumber('id');
        Route::delete('/collab-trash/{type}/{id}', [AdminTrashController::class, 'forceDelete'])->name('trash.force-delete')->whereNumber('id');
        
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::get('/users/create', [AdminController::class, 'createUser'])->name('users.create');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::get('/users/import', [AdminController::class, 'importUsersForm'])->name('users.import-form');
        Route::post('/users/import', [AdminController::class, 'importUsers'])->name('users.import');
        Route::get('/users/{user}/edit', [AdminController::class, 'editUser'])->name('users.edit');
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('users.destroy');

        Route::resource('departments', AdminController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::get('/departments', [AdminController::class, 'departments'])->name('departments');
        Route::get('/departments/create', [AdminController::class, 'createDepartment'])->name('departments.create');
        Route::post('/departments', [AdminController::class, 'storeDepartment'])->name('departments.store');
        Route::get('/departments/{department}/edit', [AdminController::class, 'editDepartment'])->name('departments.edit');
        Route::put('/departments/{department}', [AdminController::class, 'updateDepartment'])->name('departments.update');
        Route::delete('/departments/{department}', [AdminController::class, 'deleteDepartment'])->name('departments.destroy');

        Route::resource('roles', AdminController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::get('/roles', [AdminController::class, 'roles'])->name('roles');
        Route::get('/roles/create', [AdminController::class, 'createRole'])->name('roles.create');
        Route::post('/roles', [AdminController::class, 'storeRole'])->name('roles.store');
        Route::get('/roles/{role}/edit', [AdminController::class, 'editRole'])->name('roles.edit');
        Route::put('/roles/{role}', [AdminController::class, 'updateRole'])->name('roles.update');
        Route::delete('/roles/{role}', [AdminController::class, 'deleteRole'])->name('roles.destroy');

        Route::get('/statuses', [AdminController::class, 'statuses'])->name('statuses');
        Route::get('/statuses/{status}/edit', [AdminController::class, 'editStatus'])->name('statuses.edit');
        Route::put('/statuses/{status}', [AdminController::class, 'updateStatus'])->name('statuses.update');

        Route::get('/task-types', [AdminController::class, 'taskTypes'])->name('task-types');
        Route::get('/task-types/create', [AdminController::class, 'createTaskType'])->name('task-types.create');
        Route::post('/task-types', [AdminController::class, 'storeTaskType'])->name('task-types.store');
        Route::get('/task-types/{taskType}/edit', [AdminController::class, 'editTaskType'])->name('task-types.edit');
        Route::put('/task-types/{taskType}', [AdminController::class, 'updateTaskType'])->name('task-types.update');
        Route::delete('/task-types/{taskType}', [AdminController::class, 'deleteTaskType'])->name('task-types.destroy');

        Route::get('/file-repository', [AdminController::class, 'fileRepository'])->name('file-repository');
        Route::get('/operation-logs', [AdminController::class, 'operationLogs'])->name('operation-logs');
        Route::get('/send-notification', [AdminController::class, 'sendNotification'])->name('send-notification');
        Route::post('/send-notification', [AdminController::class, 'postNotification'])->name('notification.post');
        Route::get('/services', [AdminController::class, 'services'])->name('services');
        Route::post('/backup', [AdminController::class, 'backupDatabase'])->name('backup');
        Route::get('/todos', [AdminController::class, 'todos'])->name('todos');

        // 聊天记录管理
        Route::get('/chat-messages', [AdminController::class, 'chatMessages'])->name('chat-messages');
        Route::post('/chat-messages/delete', [AdminController::class, 'deleteChatMessages'])->name('chat-messages.delete');
        Route::post('/chat-messages/clear-project', [AdminController::class, 'clearProjectChat'])->name('chat-messages.clear-project');

        // 企业微信集成管理
        Route::prefix('wecom')->name('wecom.')->group(function () {
            Route::get('/settings', [WecomSettingsController::class, 'index'])->name('settings');
            Route::post('/settings', [WecomSettingsController::class, 'save'])->name('settings.save');
            Route::get('/test', [WecomSettingsController::class, 'testConnection'])->name('test');
            Route::get('/message-logs', [WecomSettingsController::class, 'messageLogs'])->name('message-logs');
        });
    });

    Route::prefix('project')->name('project.')->group(function () {
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::get('/my-projects', [ProjectController::class, 'myProjects'])->name('my-projects');
        // 按状态拆分的项目列表路由
        Route::get('/my-projects/overdue', [ProjectController::class, 'myOverdueProjects'])->name('my-projects.overdue');
        Route::get('/my-projects/in-progress', [ProjectController::class, 'myInProgressProjects'])->name('my-projects.in-progress');
        Route::get('/my-projects/completed', [ProjectController::class, 'myCompletedProjects'])->name('my-projects.completed');
        Route::get('/my-projects/suspended', [ProjectController::class, 'mySuspendedProjects'])->name('my-projects.suspended');
        Route::get('/create', [ProjectController::class, 'create'])->name('create');
        Route::post('/', [ProjectController::class, 'store'])->name('store');
        Route::get('/{project}', [ProjectController::class, 'show'])->name('show');
        Route::get('/{project}/edit', [ProjectController::class, 'edit'])->name('edit');
        Route::put('/{project}', [ProjectController::class, 'update'])->name('update');
        Route::delete('/{project}', [ProjectController::class, 'delete'])->name('destroy');
        Route::get('/{project}/logs', [ProjectController::class, 'logs'])->name('logs');
        Route::get('/{project}/change', [ProjectController::class, 'change'])->name('change');
        Route::put('/{project}/change', [ProjectController::class, 'updateChange'])->name('update-change');
        Route::get('/{project}/gantt', [ProjectController::class, 'gantt'])->name('gantt');
        
        Route::post('/{project}/pause', [ProjectController::class, 'pause'])->name('pause');
        Route::post('/{project}/resume', [ProjectController::class, 'resume'])->name('resume');
        
        Route::get('/get-designers/{departmentId}', [ProjectController::class, 'getDesigners'])->name('get-designers');
        Route::get('/get-managers/{departmentId}', [ProjectController::class, 'getManagers'])->name('get-managers');
        Route::get('/get-template/{templateId}', [ProjectController::class, 'getTemplateConfig'])->name('get-template');

        // 协同办公（OnlyOffice 协同编辑）
        Route::get('/{project}/collab', [CollabDocumentController::class, 'index'])->name('collab.index');
        Route::get('/{project}/collab/create', [CollabDocumentController::class, 'create'])->name('collab.create');
        Route::post('/{project}/collab', [CollabDocumentController::class, 'store'])->name('collab.store');
        Route::get('/{project}/collab/{doc}/open', [CollabDocumentController::class, 'open'])->name('collab.open');
        Route::delete('/{project}/collab/{doc}', [CollabDocumentController::class, 'destroy'])->name('collab.destroy');

        // 电话会议（纯音频 + OnlyOffice 文稿演示）
        Route::get('/{project}/voice', [VoiceController::class, 'index'])->name('voice.index');
        Route::get('/{project}/voice/token', [VoiceController::class, 'issueToken'])->name('voice.token');
        Route::get('/{project}/voice/members', [VoiceController::class, 'members'])->name('voice.members');
        Route::get('/{project}/voice/doc-config/{doc}', [VoiceController::class, 'docConfig'])->name('voice.doc-config');
        Route::post('/{project}/voice/upload-demo', [VoiceController::class, 'uploadDemoFile'])->name('voice.upload-demo');
        Route::get('/{project}/voice/history', [VoiceController::class, 'history'])->name('voice.history');
    });

    // Swoole 信令服务回调（内网，后续用 secret 校验）
    Route::post('voice/hooks/enter', [VoiceController::class, 'hookEnter'])->name('voice.hooks.enter');
    Route::post('voice/hooks/leave', [VoiceController::class, 'hookLeave'])->name('voice.hooks.leave');
    
    // 项目履历路由
    Route::prefix('history')->name('history.')->group(function () {
        Route::get('/project/{project}', [HistoryController::class, 'projectHistory'])->name('project.history');
    });
    
    // 聊天室路由
    Route::get('project/{projectId}/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('project/{projectId}/messages', [MessageController::class, 'store'])->name('messages.store');
    Route::post('project/{projectId}/messages/clear', [MessageController::class, 'clearMine'])->name('messages.clear-mine');
    Route::delete('project/{projectId}/messages/{id}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::get('messages/{id}/file', [MessageController::class, 'file'])->name('messages.file');
    
    // 私聊路由
    Route::get('project/{projectId}/chat/{targetUserId}', [PrivateMessageController::class, 'show'])->name('messages.private');
    Route::post('project/{projectId}/chat/{targetUserId}/clear', [PrivateMessageController::class, 'clearMine'])->name('messages.private.clear-mine');
    Route::get('api/project/{projectId}/chat/members', [PrivateMessageController::class, 'members'])->name('api.chat.members');
    Route::get('api/project/{projectId}/chat/history/{targetUserId}', [PrivateMessageController::class, 'history'])->name('api.chat.history');
    Route::post('api/project/{projectId}/chat/send', [PrivateMessageController::class, 'send'])->name('api.chat.send');

    // 用户头像路由
    Route::get('profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::post('profile/avatar/upload', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.upload');

    // 用户个人中心 — 基本资料 / 修改密码
    Route::get('profile/edit', [ProfileController::class, 'editPersonal'])->name('profile.edit');
    Route::post('profile/update', [ProfileController::class, 'updatePersonal'])->name('profile.update');
    Route::get('profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
    Route::post('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    // 用户个人 — 企业微信绑定管理
    Route::prefix('profile/wecom')->name('profile.wecom.')->group(function () {
        Route::get('/', [UserWecomController::class, 'index'])->name('index');
        Route::post('/unbind', [UserWecomController::class, 'unbind'])->name('unbind');
        Route::post('/settings', [UserWecomController::class, 'updateSettings'])->name('settings.update');
    });

    Route::prefix('task')->name('task.')->group(function () {
        Route::get('/', [TaskController::class, 'index'])->name('index');
        Route::get('/my-tasks', [TaskController::class, 'myTasks'])->name('my-tasks');
        // 按状态拆分的执行人任务列表路由
        Route::get('/my-tasks/pending-execution', [TaskController::class, 'myPendingExecutionTasks'])->name('my-tasks.pending-execution');
        Route::get('/my-tasks/overdue', [TaskController::class, 'myOverdueTasks'])->name('my-tasks.overdue');
        Route::get('/my-tasks/in-progress', [TaskController::class, 'myInProgressTasks'])->name('my-tasks.in-progress');
        Route::get('/my-tasks/completed', [TaskController::class, 'myCompletedTasks'])->name('my-tasks.completed');
        Route::get('/my-tasks/suspended', [TaskController::class, 'mySuspendedTasks'])->name('my-tasks.suspended');
        Route::get('/my-audit-tasks', [TaskController::class, 'myAuditTasks'])->name('my-audit-tasks');
        // 按状态拆分的审核任务列表路由
        Route::get('/my-audit-tasks/pending-audit', [TaskController::class, 'myPendingAuditTasks'])->name('my-audit-tasks.pending-audit');
        Route::get('/my-audit-tasks/overdue', [TaskController::class, 'myOverdueAuditTasks'])->name('my-audit-tasks.overdue');
        Route::get('/my-audit-tasks/in-progress', [TaskController::class, 'myInProgressAuditTasks'])->name('my-audit-tasks.in-progress');
        Route::get('/my-audit-tasks/completed', [TaskController::class, 'myCompletedAuditTasks'])->name('my-audit-tasks.completed');
        Route::get('/my-audit-tasks/suspended', [TaskController::class, 'mySuspendedAuditTasks'])->name('my-audit-tasks.suspended');
        Route::get('/{task}', [TaskController::class, 'show'])->name('show');
        Route::get('/{task}/upload', [TaskController::class, 'uploadFile'])->name('upload');
        Route::post('/{task}/upload', [TaskController::class, 'storeFile'])->name('store-file');
        Route::get('/{task}/audit', [TaskController::class, 'audit'])->name('audit');
        Route::post('/{task}/approve', [TaskController::class, 'approve'])->name('approve');
        Route::post('/{task}/reject', [TaskController::class, 'reject'])->name('reject');
        Route::get('/{task}/apply-extension', [TaskController::class, 'applyExtension'])->name('apply-extension');
        Route::post('/{task}/apply-extension', [TaskController::class, 'storeExtension'])->name('store-extension');
        Route::get('/{task}/update', [TaskController::class, 'updateFile'])->name('update-file');
        Route::post('/{task}/update', [TaskController::class, 'storeUpdate'])->name('store-update');
    });

    Route::prefix('file')->name('file.')->group(function () {
        Route::get('/', [FileController::class, 'index'])->name('index');
        Route::get('/resource-files', [FileController::class, 'resourceFiles'])->name('resource-files');
        Route::get('/manage-resource-files', [FileController::class, 'manageResourceFiles'])->name('manage-resource-files');
        Route::get('/{file}/preview', [FileController::class, 'preview'])->name('preview');
        Route::get('/{file}/preview-content', [FileController::class, 'previewFileContent'])->name('preview-content');
        Route::get('/{file}/download', [FileController::class, 'download'])->name('download');
        Route::get('/upload-department', [FileController::class, 'uploadDepartmentFile'])->name('upload-department');
        Route::post('/upload-department', [FileController::class, 'storeDepartmentFile'])->name('store-department');
        Route::get('/department/{file}/preview', [FileController::class, 'previewDepartmentFile'])->name('preview-department');
        Route::get('/department/{file}/download', [FileController::class, 'downloadDepartmentFile'])->name('download-department');
        Route::post('/department/{file}/update', [FileController::class, 'updateDepartmentFile'])->name('update-department');
        Route::delete('/department/{file}', [FileController::class, 'deleteDepartmentFile'])->name('delete-department');
    });

    Route::prefix('notification')->name('notification.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-read');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    // 企微文件管理
    Route::prefix('wecom-file')->name('wecom.file.')->group(function () {
        Route::get('/', [WecomFileController::class, 'index'])->name('index');
        Route::get('/{file}/preview', [WecomFileController::class, 'preview'])->name('preview');
        Route::get('/{file}/download', [WecomFileController::class, 'download'])->name('download');
        Route::post('/{file}/archive', [WecomFileController::class, 'archive'])->name('archive');
        Route::delete('/{file}', [WecomFileController::class, 'destroy'])->name('destroy');
        Route::post('/send-to-wecom', [WecomFileController::class, 'sendToWecom'])->name('send');
        Route::get('/api/bound-users', [WecomFileController::class, 'boundUsers'])->name('bound-users');
    });

    Route::prefix('todo')->name('todo.')->group(function () {
        Route::get('/', [TodoController::class, 'index'])->name('index');
        Route::post('/{todo}/complete', [TodoController::class, 'complete'])->name('complete');
        Route::post('/extension/{application}/approve', [TodoController::class, 'approveExtension'])->name('extension.approve');
        Route::post('/extension/{application}/reject', [TodoController::class, 'rejectExtension'])->name('extension.reject');
    });

    // 延期申请审批路由（仅项目经理可见）
    Route::prefix('extension')->name('extension.')->group(function () {
        Route::get('/', [ExtensionController::class, 'index'])->name('index');
        Route::post('/{application}/approve', [ExtensionController::class, 'approve'])->name('approve');
        Route::post('/{application}/reject', [ExtensionController::class, 'reject'])->name('reject');
    });

    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('/department-files', [SupportController::class, 'departmentFiles'])->name('department-files');
        Route::get('/send-notification', [SupportController::class, 'sendNotification'])->name('send-notification');
        Route::post('/send-notification', [SupportController::class, 'postNotification'])->name('notification.post');
        Route::get('/upload-resource-file', [SupportController::class, 'uploadResourceFile'])->name('upload-resource-file');
        Route::post('/upload-resource-file', [SupportController::class, 'storeResourceFile'])->name('store-resource-file');
    });

    // 项目模板路由
    Route::prefix('project-template')->name('project-template.')->group(function () {
        Route::get('/', [ProjectTemplateController::class, 'index'])->name('index');
        Route::get('/create', [ProjectTemplateController::class, 'create'])->name('create');
        Route::post('/', [ProjectTemplateController::class, 'store'])->name('store');
        Route::get('/{projectTemplate}/edit', [ProjectTemplateController::class, 'edit'])->name('edit');
        Route::put('/{projectTemplate}', [ProjectTemplateController::class, 'update'])->name('update');
        Route::delete('/{projectTemplate}', [ProjectTemplateController::class, 'destroy'])->name('destroy');
    });

    // 知识库路由
    Route::prefix('knowledge')->name('knowledge.')->group(function () {
        Route::get('/', [KnowledgeController::class, 'index'])->name('index');
        Route::get('/create', [KnowledgeController::class, 'create'])->name('create');
        Route::post('/', [KnowledgeController::class, 'store'])->name('store');
        Route::get('/{knowledge}/edit', [KnowledgeController::class, 'edit'])->name('edit');
        Route::put('/{knowledge}', [KnowledgeController::class, 'update'])->name('update');
        Route::delete('/{knowledge}', [KnowledgeController::class, 'destroy'])->name('destroy');
        Route::get('/{knowledge}', [KnowledgeController::class, 'show'])->name('show');
        Route::post('/{knowledge}/docs', [KnowledgeController::class, 'docStore'])->name('doc.store');
        Route::post('/{knowledge}/docs/{doc}', [KnowledgeController::class, 'docUpdate'])->name('doc.update');
        Route::delete('/{knowledge}/docs/{doc}', [KnowledgeController::class, 'docDestroy'])->name('doc.destroy');
        Route::get('/{knowledge}/docs/{doc}/read', [KnowledgeController::class, 'docRead'])->name('doc.read');
    });
});

// 协同办公 —— OnlyOffice 服务器访问接口（无登录态，doc_key 校验）
Route::get('collab/download/{doc}', [CollabDocumentController::class, 'download'])->name('collab.download');
Route::post('collab/callback/{doc}', [CollabDocumentController::class, 'callback'])->name('collab.callback');