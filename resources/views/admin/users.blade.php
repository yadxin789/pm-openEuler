@extends('layouts.app')

@section('title', '用户管理')

@section('content')
<h1 class="mb-4">用户管理</h1>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">添加用户</a>
        <a href="{{ route('admin.users.import-form') }}" class="btn btn-outline-success">导入用户</a>
    </div>
    <a href="{{ route('admin.users.deleted') }}" class="btn btn-outline-secondary">查看删除用户</a>
</div>

<table class="table table-striped">
    <thead>
        <tr>
            <th>用户名</th>
            <th>姓名</th>
            <th>部门</th>
            <th>角色</th>
            <th>状态</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @foreach($users as $user)
        <tr>
            <td>{{ $user->username }}</td>
            <td>{{ $user->name }}</td>
            <td>{{ $user->department->name ?? '-' }}</td>
            <td>{{ $user->role->name ?? '-' }}</td>
            <td>{{ $user->is_active == 1 ? '启用' : '禁用' }}</td>
            <td>
                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-primary">编辑</a>
                <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除？')">删除</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $users->links() }}
@endsection