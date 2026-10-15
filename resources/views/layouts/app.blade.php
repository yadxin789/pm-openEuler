<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '产品研发管理系统')</title>
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    <style>
        body {
            background-color: #f5f7fa;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
        }
        .sidebar a {
            color: #fff;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar .active {
            background-color: rgba(102, 126, 234, 0.3);
            border-left: 3px solid #667eea;
        }
        .navbar {
            background: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .user-info {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
        }
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 0.75rem;
        }
        .user-name {
            color: #333;
            font-size: 0.9rem;
        }
        .user-role {
            color: #666;
            font-size: 0.75rem;
        }
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    </style>
    @yield('head')
</head>
<body>
    @php
        $currentUser = auth()->user();
        $isAdmin = $currentUser ? $currentUser->isAdmin() : false;
        $isManager = $currentUser ? $currentUser->isProjectCreator() : false;
        $isProjectManager = $currentUser ? $currentUser->isProjectManager() : false;
        $isDeptManager = $currentUser ? $currentUser->isTaskAuditor() : false;
        $isExecutor = $currentUser ? $currentUser->isTaskExecutor() : false;
        $isSupport = $currentUser ? $currentUser->isSupport() : false;
        $userRole = $currentUser && $currentUser->role ? $currentUser->role->name : '未知';
        $userName = $currentUser ? $currentUser->name : '未知';
        
        // 计算侧边栏菜单中的任务计数
        $pendingExtensionCount = 0;
        // 项目状态徽章计数（用户参与的项目）
        $myOverdueProjectCount = 0;
        $myInProgressProjectCount = 0;
        $myCompletedProjectCount = 0;
        $mySuspendedProjectCount = 0;
        $myProjectCount = 0;
        // 执行人任务状态徽章计数
        $myOverdueTaskCount = 0;
        $myInProgressTaskCount = 0;
        $myCompletedTaskCount = 0;
        $mySuspendedTaskCount = 0;
        $myPendingExecutionTaskCount = 0;
        // 审核人任务状态徽章计数
        $myOverdueAuditCount = 0;
        $myInProgressAuditCount = 0;
        $myCompletedAuditCount = 0;
        $mySuspendedAuditCount = 0;
        $myPendingAuditTaskCount = 0;
        
        if ($currentUser) {
            // 用户参与的项目ID
            $executorProjectIds = \App\Models\Task::where('executor_id', $currentUser->id)->pluck('project_id');
            $auditorProjectIds = \App\Models\Task::where('auditor_id', $currentUser->id)->pluck('project_id');
            $creatorProjectIds = \App\Models\Project::where('creator_id', $currentUser->id)->pluck('id');
            $myProjectIds = $executorProjectIds->merge($auditorProjectIds)->merge($creatorProjectIds)->unique();
            
            // 徽章显示所有状态的项目总数（含未开始、逾期完成），明细在“我的项目列表”属性页中查看。
            $myProjectCount = \App\Models\Project::whereIn('id', $myProjectIds)->count();
            
            // 执行人任务计数（排除暂停项目中的任务）
            $myOverdueTaskCount = \App\Models\Task::where('executor_id', $currentUser->id)
                ->where('status', \App\States\Task\Overdue::class)
                ->whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))
                ->count();
            $myInProgressTaskCount = \App\Models\Task::where('executor_id', $currentUser->id)
                ->whereIn('status', [
                    \App\States\Task\Pending::class,
                    \App\States\Task\Rejected::class,
                    \App\States\Task\InProgress::class,
                    \App\States\Task\ForAudit::class,
                ])
                ->whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))
                ->count();
            $myCompletedTaskCount = \App\Models\Task::where('executor_id', $currentUser->id)
                ->where('status', \App\States\Task\Completed::class)
                ->whereHas('project', fn($q) => $q->where('status', '!=', \App\States\Project\Suspended::class))
                ->count();
            $mySuspendedTaskCount = \App\Models\Task::where('executor_id', $currentUser->id)
                ->whereHas('project', fn($q) => $q->where('status', \App\States\Project\Suspended::class))->count();
            // 待执行任务入口统计需要执行人上传或重新上传文件的任务。
            $myPendingExecutionTaskCount = \App\Models\Task::where('executor_id', $currentUser->id)
                ->whereIn('status', [
                    \App\States\Task\Pending::class,
                    \App\States\Task\InProgress::class,
                    \App\States\Task\Overdue::class,
                    \App\States\Task\Rejected::class,
                ])
                ->whereHas('project', fn($q) => $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]))
                ->count();
            
            // 审核人任务计数（排除已暂停项目中的任务）
            $myOverdueAuditCount = \App\Models\Task::where('auditor_id', $currentUser->id)
                ->where('status', \App\States\Task\Overdue::class)
                ->whereHas('project', fn($q) => $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]))
                ->count();
            $myInProgressAuditCount = \App\Models\Task::where('auditor_id', $currentUser->id)
                ->whereIn('status', [
                    \App\States\Task\Pending::class,
                    \App\States\Task\Rejected::class,
                    \App\States\Task\InProgress::class,
                    \App\States\Task\ForAudit::class,
                ])
                ->whereHas('project', fn($q) => $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]))
                ->count();
            $myCompletedAuditCount = \App\Models\Task::where('auditor_id', $currentUser->id)
                ->where('status', \App\States\Task\Completed::class)
                ->whereHas('project', fn($q) => $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]))
                ->count();
            $mySuspendedAuditCount = \App\Models\Task::where('auditor_id', $currentUser->id)
                ->whereHas('project', fn($q) => $q->where('status', \App\States\Project\Suspended::class))->count();
            // 待审核任务入口仅统计需要审核人处理的待审核、已逾期任务。
            $myPendingAuditTaskCount = \App\Models\Task::where('auditor_id', $currentUser->id)
                ->whereIn('status', [
                    \App\States\Task\ForAudit::class,
                    \App\States\Task\Overdue::class,
                ])
                ->whereHas('project', fn($q) => $q->whereNotIn('status', [
                    \App\States\Project\Suspended::class,
                ]))
                ->count();
            
            // 项目创建人/项目总经理的待审核延期申请数量
            $pendingExtensionCount = \App\Models\ExtensionApplication::where('approver_id', $currentUser->id)
                ->where('status', 'pending')
                ->count();
        }
    @endphp
    
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-none d-md-block sidebar" aria-label="主导航">
                <div class="sidebar-sticky">
                    <div class="p-4">
                        <h3 class="text-white text-center">产品研发管理系统</h3>
                        <p class="mb-0" style="color: #aaa;">研发全生命周期管理</p>
                    </div>
                    <ul class="nav flex-column" role="navigation">
                        <!-- 所有角色都有首页 -->
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                                <i class="fa fa-home me-2"></i> 首页
                            </a>
                        </li>
                        
                        <!-- 管理员菜单 -->
                        @if($isAdmin)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.users') ? 'active' : '' }}" href="{{ route('admin.users') }}">
                                <i class="fa fa-users me-2"></i> 用户管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.departments') ? 'active' : '' }}" href="{{ route('admin.departments') }}">
                                <i class="fa fa-building me-2"></i> 部门管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.roles') ? 'active' : '' }}" href="{{ route('admin.roles') }}">
                                <i class="fa fa-key me-2"></i> 角色管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.statuses') ? 'active' : '' }}" href="{{ route('admin.statuses') }}">
                                <i class="fa fa-toggle-on me-2"></i> 状态管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.task-types') ? 'active' : '' }}" href="{{ route('admin.task-types') }}">
                                <i class="fa fa-list-alt me-2"></i> 任务类型管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.chat-messages*') ? 'active' : '' }}" href="{{ route('admin.chat-messages') }}">
                                <i class="fa fa-comments me-2"></i> 聊天记录管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.file-repository') ? 'active' : '' }}" href="{{ route('admin.file-repository') }}">
                                <i class="fa fa-folder-open me-2"></i> 文件仓库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.trash*') || request()->routeIs('admin.users.deleted') ? 'active' : '' }}" href="{{ route('admin.trash') }}">
                                <i class="fa fa-trash me-2"></i> 回收站
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('knowledge.*') ? 'active' : '' }}" href="{{ route('knowledge.index') }}">
                                <i class="fa fa-book me-2"></i> 知识库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.operation-logs') ? 'active' : '' }}" href="{{ route('admin.operation-logs') }}">
                                <i class="fa fa-file-text me-2"></i> 操作日志
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.send-notification') ? 'active' : '' }}" href="{{ route('admin.send-notification') }}">
                                <i class="fa fa-bell me-2"></i> 发送通知
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.services') ? 'active' : '' }}" href="{{ route('admin.services') }}">
                                <i class="fa fa-server me-2"></i> 后台服务
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.wecom.*') ? 'active' : '' }}" href="{{ route('admin.wecom.settings') }}">
                                <i class="fab fa-weixin me-2"></i> 企业微信
                            </a>
                        </li>
                        @endif
                        
                        <!-- 项目经理菜单（项目发起人） -->
                        @if($isManager)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('project-template.*') ? 'active' : '' }}" href="{{ route('project-template.index') }}">
                                <i class="fa fa-clone me-2"></i> 项目模板
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('project.create') ? 'active' : '' }}" href="{{ route('project.create') }}">
                                <i class="fa fa-plus-circle me-2"></i> 创建项目
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('project.my-projects*') ? 'active' : '' }}" href="{{ route('project.my-projects') }}">
                                <i class="fa fa-folder-open me-2"></i> 我的项目列表
                                @if($myProjectCount > 0)
                                <span class="badge bg-primary ms-2">{{ $myProjectCount }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('extension.index') ? 'active' : '' }}" href="{{ route('extension.index') }}">
                                <i class="fa fa-calendar me-2"></i> 审核延期申请
                                @if($pendingExtensionCount > 0)
                                <span class="badge bg-danger ms-2">{{ $pendingExtensionCount }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('file.resource-files') ? 'active' : '' }}" href="{{ route('file.resource-files') }}">
                                <i class="fa fa-file me-2"></i> 资料文件
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('knowledge.*') ? 'active' : '' }}" href="{{ route('knowledge.index') }}">
                                <i class="fa fa-book me-2"></i> 知识库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('notification.index') ? 'active' : '' }}" href="{{ route('notification.index') }}">
                                <i class="fa fa-bell me-2"></i> 通知中心
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('profile.wecom.*') ? 'active' : '' }}" href="{{ route('profile.wecom.index') }}">
                                <i class="fab fa-weixin me-2"></i> 企业微信绑定
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('wecom.file.*') ? 'active' : '' }}" href="{{ route('wecom.file.index') }}">
                                <i class="fa fa-inbox me-2"></i> 企微文件收件箱
                            </a>
                        </li>
                        @endif
                        
                        <!-- 项目总经理菜单（只读监督 + 消息通知） -->
                        @if($isProjectManager && !$isAdmin)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('project.index') ? 'active' : '' }}" href="{{ route('project.index') }}">
                                <i class="fa fa-eye me-2"></i> 全部项目
                            </a>
                        </li>
                        
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('extension.index') ? 'active' : '' }}" href="{{ route('extension.index') }}">
                                <i class="fa fa-calendar me-2"></i> 延期申请记录
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('file.resource-files') ? 'active' : '' }}" href="{{ route('file.resource-files') }}">
                                <i class="fa fa-file me-2"></i> 资料文件
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('knowledge.*') ? 'active' : '' }}" href="{{ route('knowledge.index') }}">
                                <i class="fa fa-book me-2"></i> 知识库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('notification.index') ? 'active' : '' }}" href="{{ route('notification.index') }}">
                                <i class="fa fa-bell me-2"></i> 通知中心
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.send-notification') ? 'active' : '' }}" href="{{ route('admin.send-notification') }}">
                                <i class="fa fa-paper-plane me-2"></i> 发送指令通知
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('profile.wecom.*') ? 'active' : '' }}" href="{{ route('profile.wecom.index') }}">
                                <i class="fab fa-weixin me-2"></i> 企业微信绑定
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('wecom.file.*') ? 'active' : '' }}" href="{{ route('wecom.file.index') }}">
                                <i class="fa fa-inbox me-2"></i> 企微文件收件箱
                            </a>
                        </li>
                        @endif
                        
                        <!-- 审核人菜单（部门主管） -->
                        @if($isDeptManager && !$isAdmin && !$isManager && !$isProjectManager)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('project.my-projects*') ? 'active' : '' }}" href="{{ route('project.my-projects') }}">
                                <i class="fa fa-folder-open me-2"></i> 我的项目列表
                                @if($myProjectCount > 0)
                                <span class="badge bg-primary ms-2">{{ $myProjectCount }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('task.my-audit-tasks.pending-audit') ? 'active' : '' }}" href="{{ route('task.my-audit-tasks.pending-audit') }}">
                                <i class="fa fa-tasks me-2"></i> 我的待审核任务列表
                                @if($myPendingAuditTaskCount > 0)
                                <span class="badge bg-danger ms-2">{{ $myPendingAuditTaskCount }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('file.resource-files') ? 'active' : '' }}" href="{{ route('file.resource-files') }}">
                                <i class="fa fa-file me-2"></i> 资料文件
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('knowledge.*') ? 'active' : '' }}" href="{{ route('knowledge.index') }}">
                                <i class="fa fa-book me-2"></i> 知识库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('profile.wecom.*') ? 'active' : '' }}" href="{{ route('profile.wecom.index') }}">
                                <i class="fab fa-weixin me-2"></i> 企业微信绑定
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('wecom.file.*') ? 'active' : '' }}" href="{{ route('wecom.file.index') }}">
                                <i class="fa fa-inbox me-2"></i> 企微文件收件箱
                            </a>
                        </li>
                        @endif
                        
                        <!-- 任务执行人菜单 -->
                        @if($isExecutor && !$isAdmin && !$isManager && !$isProjectManager && !$isDeptManager)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('project.my-projects*') ? 'active' : '' }}" href="{{ route('project.my-projects') }}">
                                <i class="fa fa-folder-open me-2"></i> 我的项目列表
                                @if($myProjectCount > 0)
                                <span class="badge bg-primary ms-2">{{ $myProjectCount }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('task.my-tasks.pending-execution') ? 'active' : '' }}" href="{{ route('task.my-tasks.pending-execution') }}">
                                <i class="fa fa-tasks me-2"></i> 我的待执行任务列表
                                @if($myPendingExecutionTaskCount > 0)
                                <span class="badge bg-danger ms-2">{{ $myPendingExecutionTaskCount }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('file.resource-files') ? 'active' : '' }}" href="{{ route('file.resource-files') }}">
                                <i class="fa fa-file me-2"></i> 资料文件
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('knowledge.*') ? 'active' : '' }}" href="{{ route('knowledge.index') }}">
                                <i class="fa fa-book me-2"></i> 知识库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('notification.index') ? 'active' : '' }}" href="{{ route('notification.index') }}">
                                <i class="fa fa-bell me-2"></i> 通知中心
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('profile.wecom.*') ? 'active' : '' }}" href="{{ route('profile.wecom.index') }}">
                                <i class="fab fa-weixin me-2"></i> 企业微信绑定
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('wecom.file.*') ? 'active' : '' }}" href="{{ route('wecom.file.index') }}">
                                <i class="fa fa-inbox me-2"></i> 企微文件收件箱
                            </a>
                        </li>
                        @endif
                        
                        <!-- 支援人员菜单（非任务参与人员） -->
                        @if($isSupport && !$isAdmin && !$isManager && !$isProjectManager && !$isDeptManager && !$isExecutor)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('support.index') ? 'active' : '' }}" href="{{ route('support.index') }}">
                                <i class="fa fa-life-ring me-2"></i> 支援中心
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('file.manage-resource-files') ? 'active' : '' }}" href="{{ route('file.manage-resource-files') }}">
                                <i class="fa fa-file me-2"></i> 资料文件管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('notification.index') ? 'active' : '' }}" href="{{ route('notification.index') }}">
                                <i class="fa fa-bell me-2"></i> 通知中心
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('knowledge.*') ? 'active' : '' }}" href="{{ route('knowledge.index') }}">
                                <i class="fa fa-book me-2"></i> 知识库
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('profile.wecom.*') ? 'active' : '' }}" href="{{ route('profile.wecom.index') }}">
                                <i class="fab fa-weixin me-2"></i> 企业微信绑定
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('wecom.file.*') ? 'active' : '' }}" href="{{ route('wecom.file.index') }}">
                                <i class="fa fa-inbox me-2"></i> 企微文件收件箱
                            </a>
                        </li>
                        @endif
                    </ul>
                </div>
            </nav>
            
            <main role="main" class="col-md-10 ms-sm-auto px-4">
                <nav class="navbar navbar-expand-lg navbar-light">
                    <div class="container-fluid">
                        <!-- 移动端汉堡菜单按钮 -->
                        <button class="navbar-toggler d-md-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="打开导航菜单">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                        <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                            <ul class="navbar-nav">
                                <li class="nav-item">
                                    <a class="nav-link" href="{{ route('notification.index') }}" style="display: flex; align-items: center;">
                                        <i class="fa fa-bell" style="font-size: 18px;"></i>
                                        @php
                                            $unreadCount = 0;
                                            if (auth()->check()) {
                                                $unreadCount = \App\Models\Notification::where('user_id', auth()->id())
                                                    ->where('is_read', false)
                                                    ->count();
                                            }
                                        @endphp
                                        @if($unreadCount > 0)
                                        <span class="badge badge-danger rounded-pill ms-1" style="background-color: #dc3545; color: white; width: 20px; height: 20px; border-radius: 50%; font-size: 12px; display: inline-flex; align-items: center; justify-content: center;">{{ $unreadCount }}</span>
                                        @endif
                                    </a>
                                </li>
                                
                                {{-- 我的回收站：仅登录的非管理员显示（管理员使用左侧菜单里的统一回收站） --}}
                                @if($currentUser && !$isAdmin)
                                <li class="nav-item">
                                    <a class="nav-link" href="{{ route('my-trash') }}" title="我的回收站" style="display: flex; align-items: center;">
                                        <i class="fa fa-trash" style="font-size: 18px;"></i>
                                        @php
                                            $myTrashCount = 0;
                                            if (auth()->check()) {
                                                $myTrashCount = \App\Services\TrashService::countForUser(auth()->id());
                                            }
                                        @endphp
                                        <span class="badge rounded-pill ms-1" style="background-color: {{ $myTrashCount > 0 ? '#dc3545' : '#6c757d' }}; color: white; min-width: 20px; height: 20px; border-radius: 50%; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; padding: 0 5px;">{{ $myTrashCount }}</span>
                                    </a>
                                </li>
                                @endif

                                <li class="nav-item dropdown user-info">
                                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="用户中心">
                                        <div class="user-avatar">
                                            @php
                                                $currentUser = auth()->user();
                                            @endphp
                                            @if($currentUser && $currentUser->avatar)
                                                @php
                                                    $avatarPath = str_starts_with($currentUser->avatar, 'defaults/') 
                                                        ? 'avatars/' . $currentUser->avatar 
                                                        : 'storage/avatars/' . $currentUser->avatar;
                                                @endphp
                                                <img src="{{ asset($avatarPath) }}" 
                                                     alt="用户头像" 
                                                     style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover;">
                                            @else
                                                {{ mb_substr($userName, 0, 1) }}
                                            @endif
                                        </div>
                                        <div>
                                            <div class="user-name">{{ $userName }}</div>
                                            <div class="user-role">{{ $userRole }}</div>
                                        </div>
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userMenu" style="min-width: 170px;">
                                        <li>
                                            <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                                <i class="fa fa-user me-2"></i>基本资料
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="{{ route('profile.password.edit') }}">
                                                <i class="fa fa-key me-2"></i>修改密码
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="{{ route('profile.avatar') }}">
                                                <i class="fa fa-image me-2"></i>上传头像
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('确定退出登录？')">
                                                @csrf
                                                <button type="submit" class="dropdown-item">
                                                    <i class="fa fa-sign-out me-2"></i>退出登录
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </li>

                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('profile.wecom.index') }}" style="display: flex; align-items: center;">
                                <i class="fab fa-weixin" style="font-size: 18px;"></i>
                            </a>
                        </li>
                            </ul>
                        </div>
                    </div>
                </nav>
                
                
                @yield('content')
            </main>
        </div>
    </div>
    
    <!-- 全局消息模态弹窗 -->
    <div class="modal fade" id="globalMessageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="globalMessageHeader">
                    <h5 class="modal-title" id="globalMessageTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                </div>
                <div class="modal-body" id="globalMessageBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="globalMessageBtn">确认</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 移动端 Offcanvas 侧边栏 -->
    <div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel" style="background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%); color: #fff; max-width: 280px;">
        <div class="offcanvas-header border-bottom border-secondary">
            <h5 class="offcanvas-title text-white" id="mobileSidebarLabel">产品研发管理系统</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="关闭"></button>
        </div>
        <div class="offcanvas-body p-0">
            <ul class="nav flex-column" style="font-size: 14px;">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" style="color: #fff;">
                        <i class="fa fa-home me-2"></i> 首页
                    </a>
                </li>
                @if($isAdmin)
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.users') ? 'active' : '' }}" href="{{ route('admin.users') }}" style="color: #fff;"><i class="fa fa-users me-2"></i> 用户管理</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.departments') ? 'active' : '' }}" href="{{ route('admin.departments') }}" style="color: #fff;"><i class="fa fa-building me-2"></i> 部门管理</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.services') ? 'active' : '' }}" href="{{ route('admin.services') }}" style="color: #fff;"><i class="fa fa-server me-2"></i> 后台服务</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.wecom.*') ? 'active' : '' }}" href="{{ route('admin.wecom.settings') }}" style="color: #fff;"><i class="fab fa-weixin me-2"></i> 企业微信</a></li>
                @endif
                @if($isManager)
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('project.create') ? 'active' : '' }}" href="{{ route('project.create') }}" style="color: #fff;"><i class="fa fa-plus-circle me-2"></i> 创建项目</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('project.my-projects*') ? 'active' : '' }}" href="{{ route('project.my-projects') }}" style="color: #fff;"><i class="fa fa-folder-open me-2"></i> 我的项目列表</a></li>
                @endif
                @if($isExecutor || $isDeptManager)
                <li class="nav-item"><a class="nav-link" href="{{ route('project.my-projects') }}" style="color: #fff;"><i class="fa fa-folder-open me-2"></i> 我的项目列表</a></li>
                @endif
                @if($isExecutor)
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('task.my-tasks.pending-execution') ? 'active' : '' }}" href="{{ route('task.my-tasks.pending-execution') }}" style="color: #fff;"><i class="fa fa-tasks me-2"></i> 待执行任务</a></li>
                @endif
                @if($isDeptManager)
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('task.my-audit-tasks.pending-audit') ? 'active' : '' }}" href="{{ route('task.my-audit-tasks.pending-audit') }}" style="color: #fff;"><i class="fa fa-tasks me-2"></i> 待审核任务</a></li>
                @endif
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('notification.index') ? 'active' : '' }}" href="{{ route('notification.index') }}" style="color: #fff;"><i class="fa fa-bell me-2"></i> 通知中心</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('profile.wecom.*') ? 'active' : '' }}" href="{{ route('profile.wecom.index') }}" style="color: #fff;"><i class="fab fa-weixin me-2"></i> 企业微信绑定</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('wecom.file.*') ? 'active' : '' }}" href="{{ route('wecom.file.index') }}" style="color: #fff;"><i class="fa fa-inbox me-2"></i> 企微文件收件箱</a></li>
                <li class="nav-item">
                    <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="nav-link btn btn-link" style="color: #fff; border: none; background: none; padding: 0 1rem;">
                            <i class="fa fa-sign-out me-2"></i> 退出登录
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>

    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/echarts.min.js') }}"></script>
    <!-- 文件预览库 -->
    <script src="{{ asset('js/pdfjs.min.js') }}"></script>
    <script src="{{ asset('js/mammoth.min.js') }}"></script>
    <script src="{{ asset('js/xlsx.full.min.js') }}"></script>
    <script>
        // 全局消息模态弹窗
        let bsModalInstance = null;

        function showGlobalModal(type, title, message) {
            const modal = document.getElementById('globalMessageModal');
            if (!modal) return;

            if (!bsModalInstance) {
                bsModalInstance = new bootstrap.Modal(modal);
            }

            const header = document.getElementById('globalMessageHeader');
            const titleEl = document.getElementById('globalMessageTitle');
            const body = document.getElementById('globalMessageBody');
            const btn = document.getElementById('globalMessageBtn');

            header.className = 'modal-header';
            btn.className = 'btn';

            if (type === 'success') {
                header.classList.add('bg-success', 'text-white');
                btn.classList.add('btn-success');
                titleEl.innerHTML = '<i class="fa fa-check-circle mr-2"></i> ' + title;
            } else {
                header.classList.add('bg-danger', 'text-white');
                btn.classList.add('btn-danger');
                titleEl.innerHTML = '<i class="fa fa-exclamation-circle mr-2"></i> ' + title;
            }

            body.innerHTML = message ? String(message).split("\n").join('<br>') : '';
            bsModalInstance.show();
        }

        // 页面加载后立即弹出错误/成功消息（不依赖 DOMContentLoaded，避免时序问题）
        (function showServerSideMessages() {
            @if(session('success'))
            showGlobalModal('success', '操作成功', @json(session('success')));
            @endif
            @if(session('error'))
            showGlobalModal('danger', '操作失败', @json(session('error')));
            @endif
            @php
                $_errorStr = '';
                if ($errors->has('error')) {
                    $_errorStr = $errors->first('error');
                } elseif ($errors->any()) {
                    $_errorStr = implode("\n", $errors->all());
                }
            @endphp
            @if($_errorStr !== '')
            showGlobalModal('danger', @json($errors->has('error') ? '操作失败' : '表单验证失败'), @json($_errorStr));
            @endif
        })();
    </script>
    @yield('scripts')
</body>
</html>