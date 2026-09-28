@extends('layouts.app')

@section('title', '任务管理')

@section('content')
<h1 class="mb-4">任务管理</h1>

<table class="table table-striped">
    <thead>
        <tr>
            <th>任务类型</th>
            <th>任务名称</th>
            <th>所属项目</th>
            <th>部门</th>
            <th>执行人</th>
            <th>审核人</th>
            <th>状态</th>
            <th>截止日期</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @foreach($tasks as $task)
        <tr>
            <td>{{ $task->taskType->name ?? '-' }}</td>
            <td><a href="{{ route('task.show', $task) }}">{{ $task->name }}</a></td>
            <td><a href="{{ route('project.show', $task->project) }}">{{ $task->project->name }}</a></td>
            <td>{{ $task->department->name }}</td>
            <td>{{ $task->executor->name }}</td>
            <td>{{ $task->auditor->name }}</td>
            <td>
                <span class="badge" style="background-color: {{ $task->status->color() }}; color: white;">
                    {{ $task->status->label() }}
                </span>
            </td>
            <td>{{ $task->end_date }}</td>
            <td>
                <a href="{{ route('task.show', $task) }}" class="btn btn-sm btn-info">查看</a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $tasks->links() }}
@endsection