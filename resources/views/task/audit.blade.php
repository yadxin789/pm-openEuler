@extends('layouts.app')

@section('title', '审核文件')

@section('content')
<h1 class="mb-4">审核文件 - {{ $task->name }}</h1>

<div class="card">
    <div class="card-header">文件信息</div>
    <div class="card-body">
        <dl class="row">
            <dt class="col-sm-4">文件名</dt>
            <dd class="col-sm-8">{{ $file->original_name }}</dd>
            <dt class="col-sm-4">版本</dt>
            <dd class="col-sm-8">V{{ $file->version }}</dd>
            <dt class="col-sm-4">上传人</dt>
            <dd class="col-sm-8">{{ $file->uploader->name }}</dd>
            <dt class="col-sm-4">上传时间</dt>
            <dd class="col-sm-8">{{ $file->created_at }}</dd>
        </dl>
        <a href="{{ route('file.download', $file) }}" class="btn btn-primary">下载文件</a>
    </div>
</div>

<div class="mt-4">
    @if($task->project->isInactive())
    <div class="alert alert-warning">
        <i class="fa fa-exclamation-triangle"></i> 项目处于已暂停状态，无法进行审核操作。
    </div>
    @else
    <div class="row">
        <div class="col-md-6">
            <form method="POST" action="{{ route('task.approve', $task) }}">
                @csrf
                <div class="mb-3">
                    <label for="reason" class="form-label">审核意见（选填）</label>
                    <textarea name="reason" id="reason" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-success w-100">通过审核</button>
            </form>
        </div>
        <div class="col-md-6">
            <form method="POST" action="{{ route('task.reject', $task) }}">
                @csrf
                <div class="mb-3">
                    <label for="reason" class="form-label">驳回原因（必填）</label>
                    <textarea name="reason" id="reason" class="form-control" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-danger w-100">驳回</button>
            </form>
        </div>
    </div>
    @endif
</div>
@endsection