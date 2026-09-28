@extends('layouts.app')

@section('title', '我的审核任务列表')

@section('content')
<h1 class="mb-4">我的审核任务列表</h1>

<!-- 提示信息 -->
<div class="alert alert-info">
    <i class="fa fa-info-circle me-2"></i>
    <strong>提示：</strong>三个列表互斥，任务只能出现在一个列表中。如果状态改变后列表未更新，请刷新页面。
</div>

<!-- 未提交任务列表区 -->
<div class="card mb-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="fa fa-clock-o me-2"></i>未提交任务 ({{ $pendingTasks->count() }})</h5>
    </div>
    <div class="card-body">
        @if($pendingTasks->count() > 0)
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>项目名称</th>
                    <th>项目状态</th>
                    <th>任务类型</th>
                    <th>任务状态</th>
                    <th>任务起始日期</th>
                    <th>任务截止日期</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pendingTasks as $task)
                <tr>
                    <td><a href="{{ route('project.show', $task->project) }}">{{ $task->project->name }}</a></td>
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
                    <td>{{ $task->start_date->format('Y-m-d') }}</td>
                    <td>{{ $task->end_date->format('Y-m-d') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted text-center py-3">暂无未提交的任务</p>
        @endif
    </div>
</div>

<!-- 待审核任务列表区 -->
<div class="card mb-4">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fa fa-hourglass-half me-2"></i>待审核任务 ({{ $forAuditTasks->count() }})</h5>
    </div>
    <div class="card-body">
        @if($forAuditTasks->count() > 0)
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>项目名称</th>
                    <th>项目状态</th>
                    <th>任务类型</th>
                    <th>任务状态</th>
                    <th>文件名</th>
                    <th>文件版本号</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($forAuditTasks as $task)
                @php
                    $file = $task->files()->where('status', 'pending')->first();
                @endphp
                <tr>
                    <td><a href="{{ route('project.show', $task->project) }}">{{ $task->project->name }}</a></td>
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
                    <td>{{ $file ? $file->original_name : '-' }}</td>
                    <td>{{ $file ? 'v' . $file->version : '-' }}</td>
                    <td>
                        @if($file)
                        <a href="{{ route('file.preview', $file) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                        <a href="{{ route('file.download', $file) }}" class="btn btn-sm btn-success">下载</a>
                        @if(!$task->project->isInactive())
                        <form action="{{ route('task.approve', $task) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('确定通过审核吗？')">通过</button>
                        </form>
                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $task->id }}">驳回</button>
                        @else
                        <span class="text-muted small">项目已暂停</span>
                        @endif
                        
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
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted text-center py-3">暂无待审核的任务</p>
        @endif
    </div>
</div>

<!-- 已完成任务列表区 -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fa fa-check-circle me-2"></i>已完成任务 ({{ $completedTasks->count() }})</h5>
    </div>
    <div class="card-body">
        @if($completedTasks->count() > 0)
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>项目名称</th>
                    <th>项目状态</th>
                    <th>任务类型</th>
                    <th>任务状态</th>
                    <th>文件名</th>
                    <th>文件版本号</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($completedTasks as $task)
                @php
                    $file = $task->files()->where('status', 'approved')->first();
                @endphp
                <tr>
                    <td><a href="{{ route('project.show', $task->project) }}">{{ $task->project->name }}</a></td>
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
                    <td>{{ $file ? $file->original_name : '-' }}</td>
                    <td>{{ $file ? 'v' . $file->version : '-' }}</td>
                    <td>
                        @if($file)
                        <a href="{{ route('file.preview', $file) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                        <a href="{{ route('file.download', $file) }}" class="btn btn-sm btn-success">下载</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted text-center py-3">暂无已完成的任务</p>
        @endif
    </div>
</div>

<!-- 逾期/进行中任务列表区 -->
<div class="card mb-4">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="fa fa-exclamation-triangle me-2"></i>逾期/进行中任务 ({{ $overdueTasks->count() }})</h5>
    </div>
    <div class="card-body">
        @if($overdueTasks->count() > 0)
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>项目名称</th>
                    <th>项目状态</th>
                    <th>任务类型</th>
                    <th>任务状态</th>
                    <th>任务起始日期</th>
                    <th>任务截止日期</th>
                </tr>
            </thead>
            <tbody>
                @foreach($overdueTasks as $task)
                <tr>
                    <td><a href="{{ route('project.show', $task->project) }}">{{ $task->project->name }}</a></td>
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
                    <td>{{ $task->start_date->format('Y-m-d') }}</td>
                    <td>{{ $task->end_date->format('Y-m-d') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted text-center py-3">暂无逾期或进行中的任务</p>
        @endif
    </div>
</div>
@endsection