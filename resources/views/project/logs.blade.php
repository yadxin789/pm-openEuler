@extends('layouts.app')

@section('title', '项目日志')

@section('content')
@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'logs'])
<div class="card mt-1">
    <div class="card-header">
        <h3 class="mb-0"><i class="fa fa-list-alt me-2 text-primary"></i>项目日志</h3>
    </div>
    <div class="card-body">
<table class="table table-striped">
    <thead>
        <tr>
            <th>时间</th>
            <th>操作人</th>
            <th>操作</th>
            <th>内容</th>
        </tr>
    </thead>
    <tbody>
        @foreach($logs as $log)
        <tr>
            <td>{{ $log->created_at }}</td>
            <td>{{ $log->user->name }}</td>
            <td>{{ $log->action }}</td>
            <td>{{ $log->content }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if($logs->isEmpty())
<p class="text-center text-muted">暂无日志</p>
@endif
    </div>
</div>
@endsection