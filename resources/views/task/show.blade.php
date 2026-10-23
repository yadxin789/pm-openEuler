@extends('layouts.app')

@section('title', '任务详情')

@section('content')
<h1 class="mb-4">{{ $task->name }}</h1>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">任务信息</div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-4">所属项目</dt>
                    <dd class="col-sm-8">
                        @if($task->project)
                        <a href="{{ route('project.show', $task->project) }}">{{ $task->project->name }}</a>
                        @else
                        <span class="text-muted">-</span>
                        @endif
                    </dd>
                    <dt class="col-sm-4">部门</dt>
                    <dd class="col-sm-8">{{ $task->department->name ?? '-' }}</dd>
                    <dt class="col-sm-4">执行人</dt>
                    <dd class="col-sm-8">{{ $task->executor->name ?? '-' }}</dd>
                    <dt class="col-sm-4">审核人</dt>
                    <dd class="col-sm-8">{{ $task->auditor->name ?? '-' }}</dd>
                    <dt class="col-sm-4">状态</dt>
                    <dd class="col-sm-8">
                        <span class="badge" style="background-color: {{ $task->status->color() }}; color: white;">
                            {{ $task->status->label() }}
                        </span>
                    </dd>
                    <dt class="col-sm-4">开始日期</dt>
                    <dd class="col-sm-8">{{ $task->start_date ? $task->start_date->format('Y-m-d') : '-' }}</dd>
                    <dt class="col-sm-4">截止日期</dt>
                    <dd class="col-sm-8">{{ $task->end_date ? $task->end_date->format('Y-m-d') : '-' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

{{-- 任务操作入口：只有「项目已暂停」才冻结，其余状态（含逾期、逾期完成）都必须能上传/变更/审核 --}}
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa fa-cogs me-2"></i>任务操作</h5>
    </div>
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        @if($task->canUploadFile())
        <a href="{{ route('task.upload', $task) }}" class="btn btn-primary">上传文件</a>
        @endif
        @if($task->canApplyExtension())
        <a href="{{ route('task.apply-extension', $task) }}" class="btn btn-warning">申请延期</a>
        @endif
        @if($task->canAudit())
        <a href="{{ route('task.audit', $task) }}" class="btn btn-success">审核文件</a>
        @endif
        @if($task->canUpdateFile())
        <a href="{{ route('task.update-file', $task) }}" class="btn btn-warning">更新文件</a>
        @endif
        @if($task->project && $task->project->isInactive())
        <span class="text-muted small">项目已暂停，任务文件的上传/变更/审核已冻结，项目恢复后自动恢复。</span>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h4>文件列表</h4>
    </div>
    <div class="card-body">
        @if($files->isEmpty())
        <p class="text-center text-muted">暂无文件</p>
        @else
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>文件名</th>
                    <th>版本</th>
                    <th>上传人</th>
                    <th>审核人</th>
                    <th>上传日期</th>
                    <th>文件审核状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($files as $file)
                <tr>
                    <td>{{ $file->original_name }}</td>
                    <td>V{{ $file->version }}</td>
                    <td>{{ $file->uploader->name ?? '-' }}</td>
                    <td>{{ $file->auditor->name ?? '-' }}</td>
                    <td>{{ $file->created_at ? $file->created_at->format('Y-m-d H:i') : '-' }}</td>
                    <td>
                        {{-- 文件状态表示审核流程状态，和任务状态分开展示。 --}}
                        @if($file->status == 'pending')
                        <span class="badge bg-warning text-dark">待审核</span>
                        @elseif($file->status == 'approved')
                        <span class="badge bg-success">已通过</span>
                        @elseif($file->status == 'rejected')
                        <span class="badge bg-danger">已驳回</span>
                        @else
                        <span class="badge bg-secondary">未知</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('file.preview', $file) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                        <a href="{{ route('file.download', $file) }}" class="btn btn-sm btn-success">下载</a>
                        {{-- 使用统一 canUpdateFile 判断（同时判断角色+ Completed/OverdueCompleted 状态 + approved 文件 + 项目激活），只有 approved 文件行才显示更新按钮 --}}
                        @if($file->status == 'approved' && $task->canUpdateFile())
                        <a href="{{ route('task.update-file', $task) }}" class="btn btn-sm btn-warning">更新</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h4>延期申请记录</h4>
    </div>
    <div class="card-body">
        @if($extensionApplications->isEmpty())
        <p class="text-center text-muted">暂无延期申请记录</p>
        @else
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>申请人</th>
                    <th>审批人</th>
                    <th>申请天数</th>
                    <th>申请理由</th>
                    <th>状态</th>
                    <th>申请时间</th>
                    <th>驳回理由</th>
                </tr>
            </thead>
            <tbody>
                @foreach($extensionApplications as $application)
                <tr>
                    <td>{{ $application->applicant->name }}</td>
                    <td>{{ $application->approver->name ?? '-' }}</td>
                    <td>{{ $application->days }}天</td>
                    <td>{{ $application->reason }}</td>
                    <td>
                        @if($application->status == 'pending')
                        <span class="badge badge-warning">待审批</span>
                        @elseif($application->status == 'approved')
                        <span class="badge badge-success">已通过</span>
                        @elseif($application->status == 'rejected')
                        <span class="badge badge-danger">已驳回</span>
                        @endif
                    </td>
                    <td>{{ $application->created_at }}</td>
                    <td>{{ $application->reject_reason ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endsection