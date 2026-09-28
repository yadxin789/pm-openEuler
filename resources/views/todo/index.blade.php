@extends('layouts.app')

@section('title', '待办事项')

@section('content')
<h1 class="mb-4">待办事项</h1>

<div class="card mb-4">
    <div class="card-header">任务相关</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>项目名称</th>
                        <th>任务类型</th>
                        <th>起始日期</th>
                        <th>截止日期</th>
                        <th>事项状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($todoTasks as $item)
                    <tr>
                        <td><a href="{{ route('project.show', $item['project']) }}">{{ $item['project']->name }}</a></td>
                        <td>{{ $item['taskType'] ? $item['taskType']->name : '未知' }}</td>
                        <td>{{ $item['task']->start_date }}</td>
                        <td>{{ $item['task']->end_date }}</td>
                        <td><span class="badge {{ $item['statusClass'] }} text-white">{{ $item['status'] }}</span></td>
                        <td>
                            @if($item['todo_type'] == 'task')
                            {{-- 任务执行人的待办：上传文件/延期申请统一用模型判断显隐（成对出现/隐藏） --}}
                            <div class="btn-group" role="group">
                                @if($item['task']->canUploadFile())
                                <a href="{{ route('task.upload', $item['task']) }}" class="btn btn-primary btn-sm">上传文件</a>
                                @endif
                                @if($item['task']->canApplyExtension())
                                <a href="{{ route('task.apply-extension', $item['task']) }}" class="btn btn-warning btn-sm">申请延期</a>
                                @endif
                                @if(!$item['task']->canUploadFile() && !$item['task']->canApplyExtension() && $item['project']->isInactive())
                                <span class="text-muted small">项目已暂停</span>
                                @endif
                            </div>
                            @elseif($item['todo_type'] == 'audit')
                            {{-- 审核人的待办（项目已暂停时禁止操作） --}}
                            @php
                                $file = $item['task']->files()->where('status', 'pending')->first();
                            @endphp
                            @if($file)
                            <div class="btn-group" role="group">
                                <a href="{{ route('file.preview', $file) }}" class="btn btn-info btn-sm" target="_blank">预览</a>
                                <a href="{{ route('file.download', $file) }}" class="btn btn-success btn-sm">下载</a>
                                @if(!$item['project']->isInactive())
                                <form action="{{ route('task.approve', $item['task']) }}" method="POST" style="display: inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('确定通过审核？')">通过</button>
                                </form>
                                <form action="{{ route('task.reject', $item['task']) }}" method="POST" style="display: inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('确定驳回？')">驳回</button>
                                </form>
                                @else
                                <span class="text-muted small">项目已暂停</span>
                                @endif
                            </div>
                            @else
                            <span class="text-muted">无文件</span>
                            @endif
                            @else
                            <span class="text-muted">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        
        @if(empty($todoTasks))
        <p class="text-center text-muted mt-4">暂无任务相关待办事项</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">延期申请审批</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>项目名称</th>
                        <th>任务名称</th>
                        <th>申请人</th>
                        <th>申请天数</th>
                        <th>申请理由</th>
                        <th>申请时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($extensionTodos as $item)
                    <tr>
                        <td><a href="{{ route('project.show', $item['project']) }}">{{ $item['project']->name }}</a></td>
                        <td><a href="{{ route('task.show', $item['task']) }}">{{ $item['task']->name }}</a></td>
                        <td>{{ $item['application']->applicant->name }}</td>
                        <td>{{ $item['application']->days }}天</td>
                        <td>{{ $item['application']->reason }}</td>
                        <td>{{ $item['application']->created_at }}</td>
                        <td>
                            <div class="btn-group" role="group">
                                @if(!$item['project']->isInactive())
                                <form action="{{ route('todo.extension.approve', $item['application']) }}" method="POST" style="display: inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('确定批准延期申请？')">批准</button>
                                </form>
                                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal-{{ $item['application']->id }}">驳回</button>
                                @else
                                <span class="text-muted small">项目已暂停</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        
        @if(empty($extensionTodos))
        <p class="text-center text-muted mt-4">暂无延期申请待审批</p>
        @endif
    </div>
</div>

{{-- 驳回确认弹窗 --}}
@foreach($extensionTodos as $item)
<div class="modal fade" id="rejectModal-{{ $item['application']->id }}" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel-{{ $item['application']->id }}" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rejectModalLabel-{{ $item['application']->id }}">驳回延期申请</h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="{{ route('todo.extension.reject', $item['application']) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="form-group">
                        <label for="reason-{{ $item['application']->id }}">驳回理由</label>
                        <textarea class="form-control" id="reason-{{ $item['application']->id }}" name="reason" rows="3" required></textarea>
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
@endforeach

<div class="d-flex justify-content-center mt-3">
    {{ $todos->links() }}
</div>
@endsection