@extends('layouts.app')

@section('title', '添加任务类型')

@section('content')
<h1 class="mb-4">添加任务类型</h1>

<form method="POST" action="{{ route('admin.task-types.store') }}">
    @csrf
    <div class="mb-3">
        <label for="name" class="form-label">任务类型名称</label>
        <input type="text" name="name" id="name" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">描述</label>
        <textarea name="description" id="description" class="form-control" rows="3"></textarea>
    </div>
    <div class="mb-3">
        <label for="department_id" class="form-label">关联部门</label>
        <select name="department_id" id="department_id" class="form-control">
            <option value="">不关联部门</option>
            @foreach($departments as $dept)
            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label for="is_required" class="form-label">是否必需</label>
        <select name="is_required" id="is_required" class="form-control">
            <option value="0">否</option>
            <option value="1">是</option>
        </select>
    </div>
    <div class="mb-3">
        <label for="sort_order" class="form-label">排序</label>
        <input type="number" name="sort_order" id="sort_order" class="form-control" value="0">
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
@endsection