@extends('layouts.app')

@section('title', '文件仓库')

@section('content')
<h1 class="mb-4">文件仓库</h1>

<!-- 搜索表单 -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.file-repository') }}" class="row g-3">
            <div class="col-md-3">
                <label for="file_name" class="form-label">文件名</label>
                <input type="text" class="form-control" id="file_name" name="file_name"
                       placeholder="输入文件名关键字" value="{{ request('file_name') }}">
            </div>
            <div class="col-md-3">
                <label for="project_name" class="form-label">项目名称</label>
                <input type="text" class="form-control" id="project_name" name="project_name"
                       placeholder="输入项目名称关键字" value="{{ request('project_name') }}">
            </div>
            <div class="col-md-2">
                <label for="date_from" class="form-label">上传日期从</label>
                <input type="date" class="form-control" id="date_from" name="date_from"
                       value="{{ request('date_from') }}">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">上传日期至</label>
                <input type="date" class="form-control" id="date_to" name="date_to"
                       value="{{ request('date_to') }}">
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">文件状态</label>
                <select class="form-select" id="status" name="status">
                    <option value="">全部</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>待审核</option>
                    <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>已通过</option>
                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>已驳回</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-search me-1"></i>查询
                </button>
                <a href="{{ route('admin.file-repository') }}" class="btn btn-outline-secondary">
                    <i class="fa fa-refresh me-1"></i>重置
                </a>
            </div>
        </form>
    </div>
</div>

<!-- 搜索结果概览 -->
<div class="mb-3 text-muted">
    共找到 <strong>{{ $files->total() }}</strong> 个文件
    @if(request()->anyFilled(['file_name', 'project_name', 'date_from', 'date_to', 'status']))
        <span class="text-warning">（已筛选）</span>
    @endif
</div>

<!-- 文件列表 -->
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>文件名</th>
                <th>项目</th>
                <th>任务</th>
                <th>上传人</th>
                <th>大小</th>
                <th>上传时间</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($files as $file)
            <tr>
                <td>
                    <i class="fa fa-file-text-o me-2 text-secondary"></i>
                    {{ $file->original_name }}
                </td>
                <td>{{ $file->task->project->name ?? '-' }}</td>
                <td>{{ $file->task->name ?? '-' }}</td>
                <td>{{ $file->uploader->name ?? '-' }}</td>
                <td>{{ number_format($file->size / 1024, 2) }} KB</td>
                <td>{{ $file->created_at->format('Y-m-d H:i') }}</td>
                <td>
                    @if($file->status == 'pending')
                    <span class="badge bg-warning text-dark">待审核</span>
                    @elseif($file->status == 'approved')
                    <span class="badge bg-success">已通过</span>
                    @elseif($file->status == 'rejected')
                    <span class="badge bg-danger">已驳回</span>
                    @endif
                </td>
                <td>
                    <a href="{{ route('file.preview', $file) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                    <a href="{{ route('file.download', $file) }}" class="btn btn-sm btn-primary">下载</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="fa fa-inbox fa-2x d-block mb-2"></i>
                    暂无匹配的文件
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="d-flex justify-content-between align-items-center">
    <div class="text-muted">
        第 {{ $files->firstItem() ?? 0 }} - {{ $files->lastItem() ?? 0 }} 条，共 {{ $files->total() }} 条
    </div>
    {{ $files->links() }}
</div>
@endsection