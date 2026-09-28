@extends('layouts.app')

@section('title', '聊天记录管理')

@section('content')
<h1 class="mb-4">聊天记录管理</h1>

<!-- 统计卡片 -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card text-center border-primary">
            <div class="card-body py-3">
                <h4 class="text-primary mb-0">{{ $stats['total'] }}</h4>
                <small class="text-muted">总消息数</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center border-info">
            <div class="card-body py-3">
                <h4 class="text-info mb-0">{{ $stats['group'] }}</h4>
                <small class="text-muted">群聊消息</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center border-success">
            <div class="card-body py-3">
                <h4 class="text-success mb-0">{{ $stats['private'] }}</h4>
                <small class="text-muted">私聊消息</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center border-warning">
            <div class="card-body py-3">
                <h4 class="text-warning mb-0">{{ $stats['with_file'] }}</h4>
                <small class="text-muted">含文件消息</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center border-danger">
            <div class="card-body py-3">
                <h4 class="text-danger mb-0">{{ $stats['total_file_size'] }}</h4>
                <small class="text-muted">文件总大小</small>
            </div>
        </div>
    </div>
</div>

<!-- 筛选区域 -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.chat-messages') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="project_id" class="form-label">项目</label>
                <select name="project_id" id="project_id" class="form-control">
                    <option value="">全部项目</option>
                    @foreach($projects as $proj)
                        <option value="{{ $proj->id }}" {{ request('project_id') == $proj->id ? 'selected' : '' }}>
                            {{ $proj->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="chat_type" class="form-label">聊天类型</label>
                <select name="chat_type" id="chat_type" class="form-control">
                    <option value="">全部</option>
                    <option value="group" {{ request('chat_type') == 'group' ? 'selected' : '' }}>群聊</option>
                    <option value="private" {{ request('chat_type') == 'private' ? 'selected' : '' }}>私聊</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="date_from" class="form-label">开始日期</label>
                <input type="date" name="date_from" id="date_from" class="form-control" value="{{ request('date_from') }}">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">结束日期</label>
                <input type="date" name="date_to" id="date_to" class="form-control" value="{{ request('date_to') }}">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa fa-search mr-1"></i>筛选
                </button>
            </div>
            <div class="col-md-1">
                <a href="{{ route('admin.chat-messages') }}" class="btn btn-outline-secondary w-100" title="重置">
                    <i class="fa fa-refresh"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- 批量操作栏 -->
<div class="mb-3 d-flex justify-content-between align-items-center">
    <div>
        <button type="button" class="btn btn-danger btn-sm" id="batch-delete-btn" disabled onclick="batchDelete()">
            <i class="fa fa-trash mr-1"></i>批量删除选中
        </button>
        <button type="button" class="btn btn-warning btn-sm" onclick="showClearModal()">
            <i class="fa fa-eraser mr-1"></i>清空项目聊天记录
        </button>
    </div>
    <small class="text-muted">
        共 {{ $messages->total() }} 条记录
    </small>
</div>

<!-- 消息列表 -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                    </th>
                    <th style="width: 60px;">ID</th>
                    <th>项目</th>
                    <th>发送者</th>
                    <th>类型</th>
                    <th>内容</th>
                    <th>文件</th>
                    <th>时间</th>
                    <th style="width: 80px;">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $message)
                <tr>
                    <td>
                        <input type="checkbox" class="message-checkbox" value="{{ $message->id }}">
                    </td>
                    <td>{{ $message->id }}</td>
                    <td>
                        <a href="{{ route('project.show', $message->project_id) }}" target="_blank" title="{{ $message->project->name ?? '' }}">
                            {{ \Illuminate\Support\Str::limit($message->project->name ?? '-', 15) }}
                        </a>
                    </td>
                    <td>{{ $message->user->username ?? '-' }}</td>
                    <td>
                        @if($message->is_private)
                            <span class="badge bg-success">私聊</span>
                            @if($message->receiver)
                                <small class="text-muted">→ {{ $message->receiver->username }}</small>
                            @endif
                        @else
                            <span class="badge bg-info">群聊</span>
                        @endif
                    </td>
                    <td>
                        <span title="{{ $message->content }}">
                            {{ \Illuminate\Support\Str::limit($message->content, 50) }}
                        </span>
                    </td>
                    <td>
                        @if($message->file_path)
                            <a href="{{ route('messages.file', $message->id) }}" target="_blank" class="text-primary" title="{{ $message->file_name }}">
                                <i class="fa fa-paperclip mr-1"></i>{{ \Illuminate\Support\Str::limit($message->file_name, 20) }}
                            </a>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td><small>{{ $message->created_at->format('Y-m-d H:i') }}</small></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                onclick="deleteSingle({{ $message->id }})" title="删除">
                            <i class="fa fa-trash"></i>
                        </button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">暂无聊天记录</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $messages->links() }}
</div>

<!-- 清空项目聊天记录模态框 -->
<div class="modal fade" id="clearModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.chat-messages.clear-project') }}">
                @csrf
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fa fa-exclamation-triangle mr-2"></i>清空项目聊天记录</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong>注意：</strong>此操作将永久删除聊天记录和关联的文件，不可恢复！
                    </div>
                    <div class="mb-3">
                        <label for="clear_project_id" class="form-label">选择项目</label>
                        <select name="project_id" id="clear_project_id" class="form-control" required>
                            <option value="">-- 请选择 --</option>
                            @foreach($projects as $proj)
                                <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="clear_chat_type" class="form-label">聊天类型</label>
                        <select name="chat_type" id="clear_chat_type" class="form-control" required>
                            <option value="all">全部（群聊+私聊）</option>
                            <option value="group">仅群聊</option>
                            <option value="private">仅私聊</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('确定要清空吗？此操作不可恢复！')">
                        <i class="fa fa-eraser mr-1"></i>确认清空
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 全选/取消全选
function toggleSelectAll(checkbox) {
    document.querySelectorAll('.message-checkbox').forEach(cb => cb.checked = checkbox.checked);
    updateBatchBtn();
}

// 更新批量删除按钮状态
function updateBatchBtn() {
    const checked = document.querySelectorAll('.message-checkbox:checked').length;
    document.getElementById('batch-delete-btn').disabled = checked === 0;
}

// 监听复选框变化
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.message-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBatchBtn);
    });
});

// 批量删除
function batchDelete() {
    const ids = Array.from(document.querySelectorAll('.message-checkbox:checked')).map(cb => cb.value);
    if (ids.length === 0) return;
    if (!confirm('确定要删除选中的 ' + ids.length + ' 条聊天记录吗？关联的文件也会被删除。')) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route('admin.chat-messages.delete') }}';
    form.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}">';

    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

// 单条删除
function deleteSingle(id) {
    if (!confirm('确定要删除这条聊天记录吗？关联的文件也会被删除。')) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route('admin.chat-messages.delete') }}';
    form.innerHTML = `
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="hidden" name="ids[]" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// 显示清空模态框
function showClearModal() {
    const modal = new bootstrap.Modal(document.getElementById('clearModal'));
    modal.show();
}
</script>
@endsection
