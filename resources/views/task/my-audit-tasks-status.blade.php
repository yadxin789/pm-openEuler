@extends('layouts.app')

@php
    // 待审核页名称不再额外拼接“审核”，避免标题重复。
    $pageTitle = $statusLabel === '待审核' ? '我的待审核任务列表' : '我的' . $statusLabel . '审核任务列表';
    $showPendingFile = in_array($statusLabel, ['进行中', '待审核']);
@endphp

@section('title', $pageTitle)

@section('content')
<h1 class="mb-4">{{ $pageTitle }}</h1>

<!-- 过滤区域 -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="name" class="form-label">项目名称</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ request('name') }}" placeholder="输入项目名称搜索">
            </div>
            <div class="col-md-2">
                <label for="date_from" class="form-label">起始日期从</label>
                <input type="date" name="date_from" id="date_from" class="form-control" value="{{ request('date_from') }}">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">起始日期至</label>
                <input type="date" name="date_to" id="date_to" class="form-control" value="{{ request('date_to') }}">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="fa fa-search me-1"></i>搜索
                </button>
                <a href="{{ url()->current() }}" class="btn btn-outline-secondary">
                    <i class="fa fa-refresh me-1"></i>重置
                </a>
            </div>
        </form>
    </div>
</div>

<!-- 审核任务列表 -->
<div class="card">
    @php
        $headerClass = match($statusLabel) {
            '逾期' => 'bg-danger text-white',
            '进行中' => 'bg-warning text-white',
            '已完成' => 'bg-success text-white',
            '暂停' => 'bg-secondary text-white',
            default => 'bg-primary text-white',
        };
    @endphp
    <div class="card-header {{ $headerClass }}">
        <h5 class="mb-0">{{ $statusLabel === '待审核' ? '待审核任务' : $statusLabel . '审核任务' }} ({{ $tasks->total() }})</h5>
    </div>
    <div class="card-body">
        @if($tasks->count() > 0)
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>项目名称</th>
                    <th>项目状态</th>
                    <th>任务类型</th>
                    <th>任务状态</th>
                    <th>起始日期</th>
                    <th>截止日期</th>
                    @if($showPendingFile || $statusLabel === '已完成')
                    <th>文件名</th>
                    <th>文件版本号</th>
                    @endif
                    @if($showPendingFile)
                    <th>操作</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($tasks as $task)
                @php
                    $pendingFile = $task->pendingFile();
                    $approvedFile = $task->approvedFile();
                    $displayFile = $showPendingFile ? $pendingFile : $approvedFile;
                @endphp
                <tr>
                    <td><a href="{{ route('project.show', $task->project) }}">{{ $task->project->name ?? '-' }}</a></td>
                    <td>
                        <span class="badge" style="background-color: {{ $task->project->status->color() }}; color: white;">
                            {{ $task->project->status->label() }}
                        </span>
                    </td>
                    <td>{{ $task->taskType->name ?? '-' }}</td>
                    <td>
                        <span class="badge" style="background-color: {{ $task->status->color() }}; color: white;">
                            {{ $task->status->label() }}
                        </span>
                    </td>
                    <td>{{ $task->start_date ? $task->start_date->format('Y-m-d') : '-' }}</td>
                    <td>{{ $task->end_date ? $task->end_date->format('Y-m-d') : '-' }}</td>
                    @if($showPendingFile || $statusLabel === '已完成')
                    <td>{{ $displayFile ? $displayFile->original_name : '-' }}</td>
                    <td>{{ $displayFile ? 'v' . $displayFile->version : '-' }}</td>
                    @endif
                    @if($showPendingFile)
                    <td>
                        {{-- 查看按钮（总是显示）--}}
                        <a href="{{ route('task.show', $task) }}" class="btn btn-sm btn-info">查看</a>

                        @if($pendingFile)
                            <a href="{{ route('file.preview', $pendingFile) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                            <a href="{{ route('file.download', $pendingFile) }}" class="btn btn-sm btn-success">下载</a>
                        @endif

                        {{-- 审核操作 —— 使用统一 canAudit 判断（同时校验状态、pending文件、项目是否激活）--}}
                        @if($task->canAudit())
                        <form action="{{ route('task.approve', $task) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('确定通过审核吗？')">通过</button>
                        </form>
                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $task->id }}">驳回</button>

                        <!-- 驳回模态框 -->
                        <div class="modal fade" id="rejectModal{{ $task->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">驳回任务</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form action="{{ route('task.reject', $task) }}" method="POST">
                                        @csrf
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label for="reason{{ $task->id }}" class="form-label">驳回原因</label>
                                                <textarea class="form-control" id="reason{{ $task->id }}" name="reason" rows="3" required></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                            <button type="submit" class="btn btn-danger">确认驳回</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @elseif($task->project->isInactive())
                        <span class="text-muted small d-block mt-1">项目已暂停</span>
                        @endif
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="d-flex justify-content-center mt-3">
            {{ $tasks->appends(request()->query())->links() }}
        </div>
        @else
        <p class="text-muted text-center py-3">暂无{{ $statusLabel }}的审核任务</p>
        @endif
    </div>
</div>
@endsection