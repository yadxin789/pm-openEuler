@extends('layouts.app')

@section('title', '编辑角色')

@section('content')
<h1 class="mb-4">编辑角色</h1>

<form method="POST" action="{{ route('admin.roles.update', $role) }}">
    @csrf
    @method('PUT')
    <div class="mb-3">
        <label for="name" class="form-label">名称</label>
        <input type="text" name="name" id="name" class="form-control" value="{{ $role->name }}" required>
    </div>
    <div class="mb-3">
        <label for="code" class="form-label">编码</label>
        <input type="text" name="code" id="code" class="form-control" value="{{ $role->code }}" required>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">描述</label>
        <textarea name="description" id="description" class="form-control">{{ $role->description }}</textarea>
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
@endsection