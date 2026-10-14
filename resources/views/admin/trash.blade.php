@extends('layouts.app')

@section('title', '回收站')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><i class="fa fa-trash me-2"></i>回收站</h1>
        <div>
            <a href="{{ route('admin.users.deleted') }}" class="btn btn-outline-secondary me-2">
                <i class="fa fa-user-times me-1"></i>已删除用户（{{ $counts['users'] ?? 0 }}）
            </a>
            <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary">
                <i class="fa fa-arrow-left me-1"></i>返回后台首页
            </a>
        </div>
    </div>

    <div class="alert alert-warning">
        <i class="fa fa-exclamation-triangle me-1"></i>
        <strong>仅管理员可访问。</strong>此处列出全系统被软删除的内容；「恢复」会把内容还原，「彻底删除」不可撤销，并会一并清理磁盘上的物理文件。
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        @foreach($types as $key => $meta)
            <li class="nav-item">
                <a class="nav-link {{ $type === $key ? 'active' : '' }}"
                   href="{{ route('admin.trash', ['type' => $key]) }}">
                    <i class="fa {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}
                    <span class="badge {{ ($counts[$key] ?? 0) > 0 ? 'bg-danger' : 'bg-secondary' }}">{{ $counts[$key] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fa {{ $types[$type]['icon'] }} me-2"></i>{{ $types[$type]['label'] }}
                <span class="text-muted small ms-2">共 {{ $items->total() }} 条</span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:80px;">ID</th>
                            <th>名称</th>
                            <th style="width:190px;">删除时间</th>
                            <th style="width:190px;" class="text-end">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="text-muted">#{{ $row['id'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td class="text-muted">{{ $row['deleted_at'] ? $row['deleted_at']->format('Y-m-d H:i:s') : '-' }}</td>
                                <td class="text-end">
                                    <form action="{{ route('admin.trash.restore', ['type' => $type, 'id' => $row['id']]) }}"
                                          method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fa fa-undo me-1"></i>恢复
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            data-bs-toggle="modal" data-bs-target="#forceDeleteModal"
                                            data-name="{{ $row['name'] }}"
                                            data-url="{{ route('admin.trash.force-delete', ['type' => $type, 'id' => $row['id']]) }}">
                                        <i class="fa fa-trash me-1"></i>彻底删除
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    回收站中暂无{{ $types[$type]['label'] }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($items->hasPages())
            <div class="card-footer">
                {{ $items->links() }}
            </div>
        @endif
    </div>
</div>

{{-- 彻底删除二次确认（需输入名称） --}}
<div class="modal fade" id="forceDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fa fa-exclamation-triangle me-1"></i>危险操作：彻底删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <p>即将彻底删除：<strong class="text-danger" id="fdName"></strong></p>
                <p class="text-danger mb-3">
                    此操作<strong>不可恢复</strong>，数据库记录与磁盘上的物理文件都会被一并删除。
                </p>
                <label class="form-label">请输入上面的名称以确认：</label>
                <input type="text" id="fdInput" class="form-control" autocomplete="off" placeholder="输入名称后确认按钮才会启用">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="fdConfirm" disabled>确认彻底删除</button>
            </div>
        </div>
    </div>
</div>

<form id="fdForm" method="POST" class="d-none">
    @csrf
    <input type="hidden" name="_method" value="DELETE">
</form>
@endsection

@section('scripts')
<script>
(function () {
    var modal = document.getElementById('forceDeleteModal');
    if (!modal) return;

    var form = document.getElementById('fdForm');
    var input = document.getElementById('fdInput');
    var confirmBtn = document.getElementById('fdConfirm');
    var expected = '';

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;

        expected = trigger.getAttribute('data-name') || '';
        document.getElementById('fdName').textContent = expected;
        form.setAttribute('action', trigger.getAttribute('data-url') || '');
        input.value = '';
        confirmBtn.disabled = true;
    });

    input.addEventListener('input', function () {
        confirmBtn.disabled = input.value.trim() !== expected;
    });

    confirmBtn.addEventListener('click', function () {
        if (input.value.trim() === expected) {
            form.submit();
        }
    });
})();
</script>
@endsection
