@extends('layouts.app')

@section('title', '角色管理')

@section('content')
<h1 class="mb-4">角色管理</h1>

<a href="{{ route('admin.roles.create') }}" class="btn btn-primary mb-3">添加角色</a>

<table class="table table-striped">
    <thead>
        <tr>
            <th>名称</th>
            <th>编码</th>
            <th>描述</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @foreach($roles as $role)
        <tr>
            <td>{{ $role->name }}</td>
            <td>{{ $role->code }}</td>
            <td>{{ $role->description }}</td>
            <td>
                <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-primary">编辑</a>
                <form action="{{ route('admin.roles.destroy', $role) }}" method="POST" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除？')">删除</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $roles->links() }}
@endsection