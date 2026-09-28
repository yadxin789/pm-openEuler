@extends('layouts.app')

@section('title', '待办事项管理')

@section('content')
<h1 class="mb-4">待办事项管理</h1>

<table class="table table-striped">
    <thead>
        <tr>
            <th>标题</th>
            <th>用户</th>
            <th>类型</th>
            <th>状态</th>
            <th>创建时间</th>
        </tr>
    </thead>
    <tbody>
        @foreach($todos as $todo)
        <tr>
            <td>{{ $todo->title }}</td>
            <td>{{ $todo->user->name }}</td>
            <td>{{ $todo->type }}</td>
            <td>{{ $todo->status == 'pending' ? '待处理' : '已完成' }}</td>
            <td>{{ $todo->created_at }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $todos->links() }}
@endsection