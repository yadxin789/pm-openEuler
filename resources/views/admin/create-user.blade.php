@extends('layouts.app')

@section('title', '添加用户')

@section('content')
<h1 class="mb-4">添加用户</h1>

<form method="POST" action="{{ route('admin.users.store') }}">
    @csrf
    <div class="mb-3">
        <label for="username" class="form-label">用户名</label>
        <input type="text" name="username" id="username" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">密码</label>
        <input type="password" name="password" id="password" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="name" class="form-label">姓名</label>
        <input type="text" name="name" id="name" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="department_id" class="form-label">部门</label>
        <select name="department_id" id="department_id" class="form-control" required>
            <option value="">请选择部门</option>
            @foreach($departments as $dept)
            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label for="role_id" class="form-label">角色</label>
        <select name="role_id" id="role_id" class="form-control" required>
            <option value="">请选择角色</option>
            @foreach($roles as $role)
            <option value="{{ $role->id }}">{{ $role->name }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn btn-primary">添加</button>
</form>
@endsection