@extends('layouts.app')

@section('title', '部门管理')

@section('content')
<h1 class="mb-4">部门管理</h1>

<a href="{{ route('admin.departments.create') }}" class="btn btn-primary mb-3">添加部门</a>

<table class="table table-striped">
    <thead>
        <tr>
            <th>名称</th>
            <th>编码</th>
            <th>类型</th>
            <th>排序</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @foreach($departments as $dept)
        <tr>
            <td>{{ $dept->name }}</td>
            <td>{{ $dept->code }}</td>
            <td>{{ $dept->type == 'project' ? '项目参与部门' : '非项目参与部门' }}</td>
            <td>{{ $dept->sort_order }}</td>
            <td>
                <a href="{{ route('admin.departments.edit', $dept) }}" class="btn btn-sm btn-primary">编辑</a>
                <form action="{{ route('admin.departments.destroy', $dept) }}" method="POST" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除？')">删除</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $departments->links() }}
@endsection