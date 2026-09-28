@extends('layouts.app')

@section('title', '任务类型管理')

@section('content')
<h1 class="mb-4">任务类型管理</h1>

<a href="{{ route('admin.task-types.create') }}" class="btn btn-primary mb-4">添加任务类型</a>

<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th>名称</th>
            <th>描述</th>
            <th>关联部门</th>
            <th>是否必需</th>
            <th>排序</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @foreach($taskTypes as $type)
        <tr>
            <td>{{ $type->id }}</td>
            <td>{{ $type->name }}</td>
            <td>{{ $type->description ?? '-' }}</td>
            <td>{{ $type->department->name ?? '-' }}</td>
            <td>{{ $type->is_required ? '是' : '否' }}</td>
            <td>{{ $type->sort_order }}</td>
            <td>
                <a href="{{ route('admin.task-types.edit', $type) }}" class="btn btn-sm btn-primary">编辑</a>
                <form action="{{ route('admin.task-types.destroy', $type) }}" method="POST" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除吗？')">删除</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $taskTypes->links() }}
@endsection