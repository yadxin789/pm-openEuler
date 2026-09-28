@extends('layouts.app')

@section('title', '编辑用户')

@section('content')
<h1 class="mb-4">编辑用户</h1>

<form method="POST" action="{{ route('admin.users.update', $user) }}">
    @csrf
    @method('PUT')
    <div class="mb-3">
        <label for="username" class="form-label">用户名</label>
        <input type="text" name="username" id="username" class="form-control" value="{{ $user->username }}" required>
    </div>
    <!-- <div class="mb-3">
        <label for="password" class="form-label">密码（不填则不修改）</label>
        <input type="password" name="password" id="password" class="form-control">
    </div-->
    <div class="mb-3">
        <label for="name" class="form-label">姓名</label>
        <input type="text" name="name" id="name" class="form-control" value="{{ $user->name }}" required>
    </div>
    <div class="mb-3">
        <label for="department_id" class="form-label">部门</label>
        <select name="department_id" id="department_id" class="form-control" required>
            @foreach($departments as $dept)
            <option value="{{ $dept->id }}" {{ $user->department_id == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label for="role_id" class="form-label">角色</label>
        <select name="role_id" id="role_id" class="form-control" required>
            @foreach($roles as $role)
            <option value="{{ $role->id }}" {{ $user->role_id == $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label for="is_active" class="form-label">状态</label>
        <select name="is_active" id="is_active" class="form-control" required>
            <option value="1" {{ $user->is_active ? 'selected' : '' }}>启用</option>
            <option value="0" {{ !$user->is_active ? 'selected' : '' }}>禁用</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
@endsection