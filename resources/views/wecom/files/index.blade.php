@extends('layouts.app')

@section('title', '企微文件收件箱')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">企微文件收件箱</h1>
    <small class="text-muted">企业在微信中发送给应用的文件将自动出现在这里</small>
</div>

{{-- 统计卡片 --}}
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body text-center py-2">
                <div class="fs-4 fw-bold">{{ $counts['total'] ?? 0 }}</div>
                <small>全部文件</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body text-center py-2">
                <div class="fs-4 fw-bold">{{ $counts['pending'] ?? 0 }}</div>
                <small>待处理</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center py-2">
                <div class="fs-4 fw-bold">{{ $counts['linked'] ?? 0 }}</div>
                <small>已关联</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-secondary text-white">
            <div class="card-body text-center py-2">
                <div class="fs-4 fw-bold">{{ $counts['archived'] ?? 0 }}</div>
                <small>已归档</small>
            </div>
        </div>
    </div>
</div>

{{-- 筛选栏 --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <label class="form-label mb-0" style="font-size:13px">状态:</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">全部</option>
                    @foreach($statuses as $k => $v)
                        <option value="{{ $k }}" {{ request('status') === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0" style="font-size:13px">类型:</label>
                <select name="msg_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">全部</option>
                    @foreach($types as $k => $v)
                        <option value="{{ $k }}" {{ request('msg_type') === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <a href="{{ route('wecom.file.index') }}" class="btn btn-sm btn-outline-secondary mt-3">清除筛选</a>
            </div>
        </form>
    </div>
</div>

{{-- 文件列表 --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">类型</th>
                    <th>文件名</th>
                    <th style="width:100px">大小</th>
                    <th style="width:130px">发送人</th>
                    <th style="width:160px">接收时间</th>
                    <th style="width:80px">状态</th>
                    <th style="width:180px">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $file)
                <tr>
                    <td class="text-center">
                        <i class="fa {{ $file->msg_type_icon }} fa-lg" title="{{ $file->msg_type_name }}"></i>
                    </td>
                    <td>
                        <span title="{{ $file->original_name }}">{{ \Illuminate\Support\Str::limit($file->original_name, 40) }}</span>
                        @if($file->remark)
                            <br><small class="text-muted">{{ $file->remark }}</small>
                        @endif
                    </td>
                    <td>{{ $file->formatted_size }}</td>
                    <td>
                        @if($file->user)
                            {{ $file->user->name }}
                        @else
                            <span class="text-muted">未知用户</span>
                        @endif
                    </td>
                    <td>{{ $file->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <span class="badge {{ $file->status_badge_class }}">{{ $file->status_name }}</span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="{{ $file->getPreviewUrl() }}" target="_blank" class="btn btn-outline-primary" title="预览">
                                <i class="fa fa-eye"></i>
                            </a>
                            <a href="{{ $file->getDownloadUrl() }}" class="btn btn-outline-secondary" title="下载">
                                <i class="fa fa-download"></i>
                            </a>
                            @if($file->status === 'pending')
                                <form action="{{ route('wecom.file.archive', $file) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('确定归档此文件？')">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-warning" title="归档">
                                        <i class="fa fa-archive"></i>
                                    </button>
                                </form>
                            @endif
                            <form action="{{ route('wecom.file.destroy', $file) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('确定删除此文件吗？删除后可在回收站中恢复。')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger" title="删除">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="fa fa-inbox fa-2x d-block mb-2"></i>
                        暂无文件。
                        @if(!\App\Models\WecomConfig::active())
                            <br><small>请管理员先完成企业微信配置。</small>
                        @else
                            <br><small>在企业微信应用中向该应用发送文件即可。</small>
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($files->hasPages())
    <div class="card-footer">
        {{ $files->links() }}
    </div>
    @endif
</div>

{{-- 使用说明 --}}
<div class="card mt-3">
    <div class="card-header">
        <h6 class="mb-0"><i class="fa fa-info-circle me-1"></i> 使用说明</h6>
    </div>
    <div class="card-body" style="font-size:13px;">
        <ol class="mb-0 ps-3">
            <li class="mb-1">在企业微信中找到「产品研发管理系统」应用</li>
            <li class="mb-1">向应用直接发送图片、文件、语音或视频</li>
            <li class="mb-1">系统自动接收并存储到此收件箱</li>
            <li class="mb-1">可在此预览、下载、归档或删除文件</li>
        </ol>
    </div>
</div>
@endsection