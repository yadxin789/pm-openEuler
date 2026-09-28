@extends('layouts.app')

@section('title', '后台服务状态')

@section('content')
<div class="container-fluid">
    <h4 class="mb-4"><i class="fa fa-server me-2"></i>后台服务状态</h4>

    <!-- 状态卡片 -->
    <div class="row mb-4">
        <!-- Scheduler -->
        <div class="col-md-4 mb-3">
            <div class="card border-{{ $schedulerRunning ? 'success' : 'danger' }} shadow-sm">
                <div class="card-body text-center">
                    <div class="mb-2">
                        <i class="fa fa-clock-o fa-3x text-{{ $schedulerRunning ? 'success' : 'danger' }}"></i>
                    </div>
                    <h5>定时任务 (Scheduler)</h5>
                    <span class="badge bg-{{ $schedulerRunning ? 'success' : 'danger' }} fs-6 px-3 py-2">
                        {{ $schedulerRunning ? '运行中' : '已停止' }}
                    </span>
                    <p class="text-muted mt-2 mb-0 small">
                        生产: <code>crontab schedule:run</code> · 开发: <code>schedule:work</code>
                    </p>
                    @if(!$schedulerRunning)
                    <div class="alert alert-warning mt-2 mb-0 py-1 small">
                        <i class="fa fa-exclamation-triangle me-1"></i>
                        项目状态刷新、到期提醒等定时任务将不会执行
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Queue Worker -->
        <div class="col-md-4 mb-3">
            <div class="card border-{{ $queueRunning ? 'success' : 'danger' }} shadow-sm">
                <div class="card-body text-center">
                    <div class="mb-2">
                        <i class="fa fa-tasks fa-3x text-{{ $queueRunning ? 'success' : 'danger' }}"></i>
                    </div>
                    <h5>队列处理器 (Queue)</h5>
                    <span class="badge bg-{{ $queueRunning ? 'success' : 'danger' }} fs-6 px-3 py-2">
                        {{ $queueRunning ? '运行中' : '已停止' }}
                    </span>
                    <p class="text-muted mt-2 mb-0 small">
                        <code>php artisan queue:work</code>
                    </p>
                    @if(!$queueRunning)
                    <div class="alert alert-warning mt-2 mb-0 py-1 small">
                        <i class="fa fa-exclamation-triangle me-1"></i>
                        异步通知和队列任务将积压不会处理
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Vite Dev Server -->
        <div class="col-md-4 mb-3">
            <div class="card border-{{ $viteRunning ? 'success' : 'secondary' }} shadow-sm">
                <div class="card-body text-center">
                    <div class="mb-2">
                        <i class="fa fa-code fa-3x text-{{ $viteRunning ? 'success' : 'secondary' }}"></i>
                    </div>
                    <h5>前端开发服务 (Vite)</h5>
                    <span class="badge bg-{{ $viteRunning ? 'success' : 'secondary' }} fs-6 px-3 py-2">
                        {{ $viteRunning ? '运行中' : '已停止' }}
                    </span>
                    <p class="text-muted mt-2 mb-0 small">
                        <code>npm run dev</code>
                    </p>
                    @if(!$viteRunning)
                    <div class="alert alert-light mt-2 mb-0 py-1 small">
                        <i class="fa fa-info-circle me-1"></i>
                        前端资源热更新不可用，但不影响系统运行
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- 启动说明 -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fa fa-info-circle me-2"></i>如何启动后台服务</h5>
        </div>
        <div class="card-body">
            <p class="mb-2">后台服务启动方式（按部署环境选择其一，<strong>两者不要同时使用</strong>，否则定时任务会重复执行）：</p>
            <ul class="mb-2">
                <li><strong>开发环境（Windows）</strong>：项目根目录双击运行 <code>start.bat</code>，同时启动队列、定时调度（<code>schedule:work</code>）与 Vite 开发服务。</li>
                <li><strong>生产环境（openEuler / Linux）</strong>：由 <code>deploy.sh</code> 自动配置——队列常驻进程（systemd 服务 <code>pm-queue</code>，即 <code>queue:work</code>）+ crontab 每分钟触发 <code>schedule:run</code> + <code>npm run build</code> 构建前端资源。</li>
            </ul>
            <p class="text-muted mt-2 mb-0 small">
                <i class="fa fa-lightbulb-o me-1"></i>
                提示：本页的队列状态检测 <code>queue:work</code> 进程，定时任务状态检测 crond 服务与 crontab 配置，两者独立判断。
            </p>
        </div>
    </div>

    <!-- 服务器信息 -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fa fa-desktop me-2"></i>服务器信息</h5>
        </div>
        <div class="card-body">
            <table class="table table-bordered mb-0">
                <tr>
                    <td class="bg-light" width="200"><strong>操作系统</strong></td>
                    <td>{{ $serverInfo['os'] }}</td>
                </tr>
                <tr>
                    <td class="bg-light"><strong>PHP 版本</strong></td>
                    <td>{{ $serverInfo['php_version'] }}</td>
                </tr>
                <tr>
                    <td class="bg-light"><strong>Laravel 版本</strong></td>
                    <td>{{ $serverInfo['laravel_version'] }}</td>
                </tr>
                <tr>
                    <td class="bg-light"><strong>服务器时间</strong></td>
                    <td>{{ $serverInfo['server_time'] }}</td>
                </tr>
                <tr>
                    <td class="bg-light"><strong>数据库名称</strong></td>
                    <td>{{ $serverInfo['db_name'] }} ({{ round($serverInfo['db_size'] / 1024 / 1024, 2) }} MB)</td>
                </tr>
                <tr>
                    <td class="bg-light"><strong>磁盘使用</strong></td>
                    <td>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar {{ $serverInfo['disk_used_percent'] > 80 ? 'bg-danger' : ($serverInfo['disk_used_percent'] > 60 ? 'bg-warning' : 'bg-success') }}" 
                                 role="progressbar" 
                                 style="width: {{ $serverInfo['disk_used_percent'] }}%;" 
                                 aria-valuenow="{{ $serverInfo['disk_used_percent'] }}" 
                                 aria-valuemin="0" aria-valuemax="100">
                                {{ $serverInfo['disk_used_percent'] }}%
                            </div>
                        </div>
                        <small class="text-muted">
                            空闲：{{ round($serverInfo['disk_free'] / 1024 / 1024 / 1024, 2) }} GB / 
                            总计：{{ round($serverInfo['disk_total'] / 1024 / 1024 / 1024, 2) }} GB
                        </small>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- 数据库备份 -->
    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fa fa-database me-2"></i>数据库备份</h5>
            <form action="{{ route('admin.backup') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-save me-1"></i> 立即备份
                </button>
            </form>
        </div>
        <div class="card-body">
            @if(empty($backups))
                <p class="text-muted mb-0">暂无备份文件</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>文件名</th><th>大小</th><th>日期</th></tr></thead>
                        <tbody>
                        @foreach(array_slice($backups, 0, 10) as $backup)
                            <tr>
                                <td>{{ $backup['name'] }}</td>
                                <td>{{ round($backup['size'] / 1024 / 1024, 2) }} MB</td>
                                <td>{{ $backup['date'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
