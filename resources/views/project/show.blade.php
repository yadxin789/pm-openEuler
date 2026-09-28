@extends('layouts.app')

@section('title', '项目详情')

@section('content')
@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'overview'])
<div class="row mt-1">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0"><i class="fa fa-tachometer me-2 text-primary"></i>项目概览</h3>
                <div class="btn-group">
                    {{-- 变更项目 —— 使用统一 canChange 判断（同时判断角色+非终态）--}}
                    @if($project->canChange())
                    <a href="{{ route('project.change', $project) }}" class="btn btn-warning">
                        <i class="fa fa-edit me-2"></i>变更项目
                    </a>
                    @endif
                    {{-- 暂停项目 —— 使用统一 canPause 判断（同时判断角色+进行中/逾期状态）--}}
                    @if($project->canPause())
                    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#pauseModal">
                        <i class="fa fa-pause me-2"></i>暂停项目
                    </button>
                    @endif
                    {{-- 恢复项目 —— 使用统一 canResume 判断（同时判断角色+已暂停状态）--}}
                    @if($project->canResume())
                    <form action="{{ route('project.resume', $project) }}" method="POST" class="d-inline" onsubmit="return confirm('确定恢复该项目吗？所有未完成任务的截止日期将自动顺延。')">
                        @csrf
                        <button type="submit" class="btn btn-success">
                            <i class="fa fa-play me-2"></i>重启项目
                        </button>
                    </form>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <!-- 项目基本信息 -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5 class="text-muted">项目编码</h5>
                                <p class="h4 mb-0">{{ $project->code }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5 class="text-muted">产品名称</h5>
                                <p class="h4 mb-0">{{ $project->product_name }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5 class="text-muted">产品型号</h5>
                                <p class="h4 mb-0">{{ $project->product_model }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5 class="text-muted">项目状态</h5>
                                <p class="mb-0">
                                    <span class="badge" style="background-color: {{ $project->status->color() }}; color: white; font-size: 1rem; padding: 0.5rem 1rem;">
                                        {{ $project->status->label() }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 项目成员和时间信息 -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card" style="height: 100%;">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fa fa-users me-2"></i>项目成员</h5>
                            </div>
                            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                @if($participants->count() > 0)
                                <select class="form-select" multiple size="{{ min($participants->count(), 8) }}" disabled style="width: 100%;">
                                    @foreach($participants as $participant)
                                    <option value="{{ $participant->id }}" selected>
                                        {{ $participant->name }} - {{ $participant->role->name ?? '无角色' }}
                                        @if($participant->id == $project->creator_id)
                                        (创建人)
                                        @endif
                                    </option>
                                    @endforeach
                                </select>
                                @else
                                <p class="text-muted text-center">暂无成员</p>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card" style="height: 100%;">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fa fa-calendar me-2"></i>时间信息</h5>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-0">
                                    <dt class="col-sm-5">开始日期</dt>
                                    <dd class="col-sm-7">{{ $project->start_date ? $project->start_date->format('Y-m-d') : '未设置' }}</dd>
                                    
                                    <dt class="col-sm-5">结束日期</dt>
                                    <dd class="col-sm-7">{{ $project->end_date ? $project->end_date->format('Y-m-d') : '未设置' }}</dd>
                                    
                                    <dt class="col-sm-5">创建人</dt>
                                    <dd class="col-sm-7">{{ $project->creator->name ?? '-' }}</dd>
                                    
                                    <dt class="col-sm-5">版本号</dt>
                                    <dd class="col-sm-7">{{ $project->version ?? '-' }}</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 项目统计 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fa fa-bar-chart me-2"></i>项目统计</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-2">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="h2 text-primary mb-2">{{ $stats['total_tasks'] }}</div>
                                        <div class="text-muted small">总任务数</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="h2 text-success mb-2">{{ $stats['completed_tasks'] }}</div>
                                        <div class="text-muted small">已完成</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="h2 text-warning mb-2">{{ $stats['pending_tasks'] }}</div>
                                        <div class="text-muted small">待处理</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="h2 text-info mb-2">{{ $stats['for_audit_tasks'] }}</div>
                                        <div class="text-muted small">待审核</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="h2 text-danger mb-2">{{ $stats['rejected_tasks'] }}</div>
                                        <div class="text-muted small">已驳回</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <div class="h2 text-dark mb-2">{{ $stats['overdue_tasks'] }}</div>
                                        <div class="text-muted small">已逾期</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 部门完成状态 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fa fa-building me-2"></i>部门完成状态</h5>
                    </div>
                    <div class="card-body">
                        @if(count($departmentProgress) > 0)
                        <div class="row">
                            @foreach($participatingDepts as $dept)
                            @php $progress = $departmentProgress[$dept->id] ?? 0; @endphp
                            <div class="col-md-4 mb-3">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">{{ $dept->name }}</h6>
                                        <div class="progress" style="height: 25px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                                 role="progressbar" 
                                                 style="width: {{ $progress }}%; background-color: {{ $progress >= 100 ? '#28a745' : ($progress >= 50 ? '#ffc107' : '#dc3545') }}"
                                                 aria-valuenow="{{ $progress }}" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                {{ round($progress, 1) }}%
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-muted text-center">暂无部门数据</p>
                        @endif
                    </div>
                </div>
                
                <!-- 整体进度 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fa fa-tasks me-2"></i>整体进度</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h2 class="text-primary">{{ round($project->progress, 1) }}%</h2>
                            <div class="progress" style="height: 35px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     style="width: {{ $project->progress }}%; font-size: 18px; line-height: 35px; background-color: {{ $project->progress >= 100 ? '#28a745' : '#007bff' }}">
                                    {{ round($project->progress, 1) }}%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 任务列表（管理角色查看全部；任务执行人仅查看自己负责的任务） -->
                @if($visibleTasks->count() > 0 || $canViewAllTasks)
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fa fa-list me-2"></i>任务列表</h5>
                        <div>
                            <a href="{{ route('project.logs', $project) }}" class="btn btn-sm btn-secondary me-2">
                                <i class="fa fa-history"></i> 查看项目日志
                            </a>
                            <span class="badge bg-secondary">共 {{ $visibleTasks->count() }} 个任务</span>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($visibleTasks->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>任务名称</th>
                                        <th>部门</th>
                                        <th>执行人</th>
                                        <th>审核人</th>
                                        <th>状态</th>
                                        <th>截止日期</th>
                                        <th>已上传文件</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($visibleTasks as $task)
                                    @php
                                        // 管理角色和任务审核人可查看该任务下全部文件；执行人只查看自己上传的文件。
                                        $taskFiles = ($canViewAllTasks || $task->auditor_id === auth()->id())
                                            ? $task->files
                                            : $task->files->where('uploader_id', auth()->id());
                                    @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('task.show', $task) }}" class="text-decoration-none">
                                                <strong>{{ $task->name }}</strong>
                                            </a>
                                        </td>
                                        <td>{{ $task->department->name }}</td>
                                        <td>{{ $task->executor->name }}</td>
                                        <td>{{ $task->auditor->name }}</td>
                                        <td>
                                            <span class="badge" style="background-color: {{ $task->status->color() }}; color: white;">
                                                {{ $task->status->label() }}
                                            </span>
                                        </td>
                                        <td>{{ $task->end_date ? $task->end_date->format('Y-m-d') : '未设置' }}</td>
                                        <td>
                                            @if($taskFiles->count() > 0)
                                                @foreach($taskFiles as $file)
                                                <div class="mb-2">
                                                    <span>{{ $file->original_name }}</span>
                                                </div>
                                                @endforeach
                                            @else
                                            <span class="text-muted">暂无文件</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('task.show', $task) }}" class="btn btn-sm btn-info">
                                                <i class="fa fa-eye"></i> 查看
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @else
                        <p class="text-muted text-center py-4">暂无任务</p>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
<!-- 暂停项目弹窗 —— 显示条件与暂停按钮一致（canPause）-->
@if($project->canPause())
<div class="modal fade" id="pauseModal" tabindex="-1" role="dialog" aria-labelledby="pauseModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="{{ route('project.pause', $project) }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="pauseModalLabel">
                        <i class="fa fa-pause-circle me-2"></i>暂停项目
                    </h5>
                    <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle me-2"></i>
                        暂停后，所有未完成的任务将暂时冻结。项目恢复时，截止日期将自动顺延。
                    </div>
                    <div class="form-group">
                        <label for="paused_reason"><strong>暂停原因</strong> <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="paused_reason" name="paused_reason" rows="3" 
                                  placeholder="请详细说明暂停项目的原因..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fa fa-pause me-2"></i>确认暂停
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- 暂停信息展示 -->
@if($project->status->equals(\App\States\Project\Suspended::class))
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fa fa-pause-circle me-2"></i>暂停信息</h5>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">暂停原因</dt>
            <dd class="col-sm-9">{{ $project->paused_reason ?: '未填写' }}</dd>
            
            <dt class="col-sm-3">暂停时间</dt>
            <dd class="col-sm-9">{{ $project->paused_at ? $project->paused_at->format('Y-m-d H:i') : '-' }}</dd>
            
            <dt class="col-sm-3">累计暂停天数</dt>
            <dd class="col-sm-9">{{ $project->total_paused_days ?? 0 }} 天</dd>
        </dl>
    </div>
</div>
@endif

@endsection