@extends('layouts.app')

@section('title', '编辑部门')

@section('content')
<h1 class="mb-4">编辑部门</h1>

<form method="POST" action="{{ route('admin.departments.update', $department) }}">
    @csrf
    @method('PUT')
    <div class="mb-3">
        <label for="name" class="form-label">名称</label>
        <input type="text" name="name" id="name" class="form-control" value="{{ $department->name }}" required>
    </div>
    <div class="mb-3">
        <label for="code" class="form-label">编码</label>
        <input type="text" name="code" id="code" class="form-control" value="{{ $department->code }}" required>
    </div>
    <div class="mb-3">
        <label for="type" class="form-label">类型</label>
        <select name="type" id="type" class="form-control" required>
            <option value="project" {{ $department->type == 'project' ? 'selected' : '' }}>项目参与部门</option>
            <option value="non-project" {{ $department->type == 'non-project' ? 'selected' : '' }}>非项目参与部门</option>
        </select>
    </div>
    <div class="mb-3">
        <label for="sort_order" class="form-label">排序</label>
        <input type="number" name="sort_order" id="sort_order" class="form-control" value="{{ $department->sort_order }}" required>
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
@endsection