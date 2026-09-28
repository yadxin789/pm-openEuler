@extends('layouts.app')

@section('title', '我的' . $statusLabel . '任务列表')

@section('content')
<h1 class="mb-4">我的{{ $statusLabel }}任务列表</h1>

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

<!-- 任务列表 -->
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
        <h5 class="mb-0">{{ $statusLabel }}任务 ({{ $tasks->total() }})</h5>
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
                    @if($statusLabel === '已完成')
                    <th>文件名</th>
                    <th>文件版本号</th>
                    @endif
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tasks as $task)
                @php
                    $approvedFile = $task->approvedFile();
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
                    @if($statusLabel === '已完成')
                    <td>{{ $approvedFile ? $approvedFile->original_name : '-' }}</td>
                    <td>{{ $approvedFile ? 'v' . $approvedFile->version : '-' }}</td>
                    @endif
                    <td>
                        {{-- 查看按钮（所有人都能看）--}}
                        <a href="{{ route('task.show', $task) }}" class="btn btn-sm btn-info">查看</a>

                        {{-- 上传文件按钮 —— 使用统一判断 --}}
                        @if($task->canUploadFile())
                        <a href="{{ route('task.upload', $task) }}" class="btn btn-sm btn-primary">上传文件</a>
                        @endif

                        {{-- 延期申请按钮 —— 使用统一判断 --}}
                        @if($task->canApplyExtension())
                        <a href="{{ route('task.apply-extension', $task) }}" class="btn btn-sm btn-warning">延期申请</a>
                        @endif

                        {{-- 项目暂停提示 --}}
                        @if($task->project->isInactive() && !$task->canUploadFile() && !$task->canApplyExtension())
                        <span class="text-muted small d-block mt-1">项目已暂停</span>
                        @endif

                        {{-- 已完成任务：预览 / 下载 / 更新 —— 使用统一判断 --}}
                        @if($approvedFile)
                            <a href="{{ route('file.preview', $approvedFile) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                            <a href="{{ route('file.download', $approvedFile) }}" class="btn btn-sm btn-success">下载</a>
                        @endif
                        @if($task->canUpdateFile())
                        <a href="{{ route('task.update-file', $task) }}" class="btn btn-sm btn-primary">更新</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="d-flex justify-content-center mt-3">
            {{ $tasks->appends(request()->query())->links() }}
        </div>
        @else
        <p class="text-muted text-center py-3">暂无{{ $statusLabel }}的任务</p>
        @endif
    </div>
</div>
@endsection