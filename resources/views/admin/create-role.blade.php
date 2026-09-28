@extends('layouts.app')

@section('title', '添加角色')

@section('content')
<h1 class="mb-4">添加角色</h1>

<form method="POST" action="{{ route('admin.roles.store') }}">
    @csrf
    <div class="mb-3">
        <label for="name" class="form-label">名称</label>
        <input type="text" name="name" id="name" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="code" class="form-label">编码</label>
        <input type="text" name="code" id="code" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">描述</label>
        <textarea name="description" id="description" class="form-control"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">添加</button>
</form>
@endsection