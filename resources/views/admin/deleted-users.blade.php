@extends('layouts.app')

@section('title', '已删除用户')

@section('content')
<h1 class="mb-4">已删除用户</h1>

<div class="alert alert-warning">
    <strong>⚠️ 警告：</strong>彻底删除用户是危险操作！该用户参与的项目、任务、文件、聊天记录等数据可能会受到影响。建议优先使用"恢复"功能。
</div>

<div class="d-flex gap-2 mb-3">
    <a href="{{ route('admin.users') }}" class="btn btn-secondary">返回用户列表</a>
</div>

<table class="table table-striped">
    <thead>
        <tr>
            <th>用户名</th>
            <th>姓名</th>
            <th>部门</th>
            <th>角色</th>
            <th>删除时间</th>
            <th>关联数据</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @forelse($users as $user)
        <tr>
            <td>{{ $user->username }}</td>
            <td>{{ $user->name }}</td>
            <td>{{ $user->department->name ?? '-' }}</td>
            <td>{{ $user->role->name ?? '-' }}</td>
            <td>{{ $user->deleted_at }}</td>
            <td>
                @php
                    $projectCount = \App\Models\Project::where('creator_id', $user->id)->count();
                    $taskCount = \App\Models\Task::where('executor_id', $user->id)->count() + \App\Models\Task::where('auditor_id', $user->id)->count();
                    $fileCount = \App\Models\File::where('uploader_id', $user->id)->count() + \App\Models\File::where('auditor_id', $user->id)->count();
                    $messageCount = \App\Models\Message::where('user_id', $user->id)->count();
                    $notificationCount = \App\Models\Notification::where('user_id', $user->id)->count() + \App\Models\Notification::where('sender_id', $user->id)->count();
                    $extensionCount = \App\Models\ExtensionApplication::where('applicant_id', $user->id)->count() + \App\Models\ExtensionApplication::where('approver_id', $user->id)->count();
                    $logCount = \App\Models\ProjectLog::where('user_id', $user->id)->count();
                @endphp
                <small>
                    @if($projectCount > 0)<span class="badge bg-warning me-1">项目 {{ $projectCount }}</span>@endif
                    @if($taskCount > 0)<span class="badge bg-info me-1">任务 {{ $taskCount }}</span>@endif
                    @if($fileCount > 0)<span class="badge bg-success me-1">文件 {{ $fileCount }}</span>@endif
                    @if($messageCount > 0)<span class="badge bg-primary me-1">聊天记录 {{ $messageCount }}</span>@endif
                    @if($notificationCount > 0)<span class="badge bg-secondary me-1">通知 {{ $notificationCount }}</span>@endif
                    @if($extensionCount > 0)<span class="badge bg-dark me-1">延期申请 {{ $extensionCount }}</span>@endif
                    @if($logCount > 0)<span class="badge bg-light text-dark me-1">日志 {{ $logCount }}</span>@endif
                    @if($projectCount == 0 && $taskCount == 0 && $fileCount == 0 && $messageCount == 0 && $notificationCount == 0 && $extensionCount == 0 && $logCount == 0)
                        <span class="text-muted">无关联数据</span>
                    @endif
                </small>
            </td>
            <td>
                <form action="{{ route('admin.users.restore', $user) }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">恢复</button>
                </form>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#forceDeleteModal{{ $user->id }}">
                    彻底删除
                </button>

                <!-- 彻底删除确认模态框 -->
                <div class="modal fade" id="forceDeleteModal{{ $user->id }}" tabindex="-1" aria-labelledby="forceDeleteModalLabel{{ $user->id }}" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="forceDeleteModalLabel{{ $user->id }}">⚠️ 危险操作：彻底删除用户</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-danger">
                                    <strong>此操作不可撤销！</strong>用户「{{ $user->name }}」（{{ $user->username }}）将从数据库中永久删除。
                                </div>

                                <h6 class="mt-3 mb-2">该用户关联的数据：</h6>
                                <ul class="list-group list-group-flush mb-3">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        创建的项目
                                        <span class="badge bg-primary rounded-pill">{{ $projectCount }}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        参与的任务（执行/审核）
                                        <span class="badge bg-primary rounded-pill">{{ $taskCount }}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        上传/审核的文件
                                        <span class="badge bg-primary rounded-pill">{{ $fileCount }}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        聊天记录
                                        <span class="badge bg-primary rounded-pill">{{ $messageCount }}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        通知记录
                                        <span class="badge bg-primary rounded-pill">{{ $notificationCount }}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        延期申请
                                        <span class="badge bg-primary rounded-pill">{{ $extensionCount }}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        项目日志
                                        <span class="badge bg-primary rounded-pill">{{ $logCount }}</span>
                                    </li>
                                </ul>

                                @if($projectCount > 0 || $taskCount > 0 || $fileCount > 0 || $messageCount > 0)
                                <div class="alert alert-warning">
                                    <strong>可能导致的问题：</strong>
                                    <ul class="mb-0 mt-2">
                                        @if($projectCount > 0)<li>该用户创建的项目可能无法正常访问</li>@endif
                                        @if($taskCount > 0)<li>该用户参与的任务可能显示异常</li>@endif
                                        @if($fileCount > 0)<li>该用户上传/审核的文件信息可能丢失</li>@endif
                                        @if($messageCount > 0)<li>该用户的聊天记录将显示为"已删除用户"</li>@endif
                                    </ul>
                                </div>
                                @endif

                                <div class="mb-3">
                                    <label for="confirmUsername{{ $user->id }}" class="form-label">请输入用户名「<strong>{{ $user->username }}</strong>」以确认删除：</label>
                                    <input type="text" class="form-control" id="confirmUsername{{ $user->id }}" placeholder="输入用户名确认">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-danger" id="confirmDeleteBtn{{ $user->id }}" disabled onclick="submitForceDelete('{{ $user->id }}', '{{ route('admin.users.force-delete', $user) }}')">
                                    确认彻底删除
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="7" class="text-center">暂无已删除用户</td>
        </tr>
        @endforelse
    </tbody>
</table>

{{ $users->links() }}
@endsection

@section('scripts')
<script>
// 监听输入框变化，启用/禁用删除按钮
document.addEventListener('DOMContentLoaded', function() {
    @forelse($users as $user)
    (function() {
        const input = document.getElementById('confirmUsername{{ $user->id }}');
        const btn = document.getElementById('confirmDeleteBtn{{ $user->id }}');
        if (input && btn) {
            input.addEventListener('input', function() {
                btn.disabled = this.value !== '{{ $user->username }}';
            });
        }
    })();
    @empty
    @endforelse
});

// 提交彻底删除表单
function submitForceDelete(userId, url) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    
    // 添加 CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_token';
    csrfInput.value = '{{ csrf_token() }}';
    form.appendChild(csrfInput);
    
    // 添加 DELETE 方法伪装
    const methodInput = document.createElement('input');
    methodInput.type = 'hidden';
    methodInput.name = '_method';
    methodInput.value = 'DELETE';
    form.appendChild(methodInput);
    
    document.body.appendChild(form);
    form.submit();
}
</script>
@endsection