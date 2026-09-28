@extends('layouts.app')

@section('title', '编辑任务类型')

@section('content')
<h1 class="mb-4">编辑任务类型</h1>

<form method="POST" action="{{ route('admin.task-types.update', $taskType) }}">
    @csrf
    @method('PUT')
    <div class="mb-3">
        <label for="name" class="form-label">任务类型名称</label>
        <input type="text" name="name" id="name" class="form-control" value="{{ $taskType->name }}" required>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">描述</label>
        <textarea name="description" id="description" class="form-control" rows="3">{{ $taskType->description }}</textarea>
    </div>
    <div class="mb-3">
        <label for="department_id" class="form-label">关联部门</label>
        <select name="department_id" id="department_id" class="form-control">
            <option value="">不关联部门</option>
            @foreach($departments as $dept)
            <option value="{{ $dept->id }}" {{ $taskType->department_id == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label for="is_required" class="form-label">是否必需</label>
        <select name="is_required" id="is_required" class="form-control">
            <option value="0" {{ $taskType->is_required == 0 ? 'selected' : '' }}>否</option>
            <option value="1" {{ $taskType->is_required == 1 ? 'selected' : '' }}>是</option>
        </select>
    </div>
    <div class="mb-3">
        <label for="sort_order" class="form-label">排序</label>
        <input type="number" name="sort_order" id="sort_order" class="form-control" value="{{ $taskType->sort_order }}">
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
@endsection