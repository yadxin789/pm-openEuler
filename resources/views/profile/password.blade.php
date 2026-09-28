@extends('layouts.app')

@section('title', '修改密码')

@section('content')
<div class="container">
    <h1 class="mb-4">修改密码</h1>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('profile.password.update') }}">
                @csrf
                <table class="table table-bordered form-table mb-0">
                    <tr>
                        <td class="form-table-label">旧密码<font color="red">*</font></td>
                        <td>
                            <input type="password" name="old_password" class="form-control" placeholder="请输入旧密码" autocomplete="off" required>
                            @error('old_password')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </td>
                        <td class="form-table-label">用户名</td>
                        <td>{{ $user->username }}</td>
                    </tr>
                    <tr>
                        <td class="form-table-label">新密码<font color="red">*</font></td>
                        <td>
                            <input type="password" name="password" class="form-control" placeholder="请输入新密码（不少于 6 位）" autocomplete="new-password" required>
                            @error('password')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </td>
                        <td class="form-table-label">确认新密码<font color="red">*</font></td>
                        <td>
                            <input type="password" name="password_confirmation" class="form-control" placeholder="请再次输入新密码" autocomplete="new-password" required>
                        </td>
                    </tr>
                </table>
                <div class="pt-4">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> 立即提交</button>
                    <button type="reset" class="btn btn-outline-secondary">重置</button>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <i class="fa fa-info-circle"></i> 修改成功后需要重新登录，请使用新密码登录系统。
                </p>
            </form>
        </div>
    </div>
</div>

<style>
    .form-table td {
        padding: 12px 16px !important;
        vertical-align: middle;
    }
    .form-table-label {
        width: 130px;
        background-color: #f8f9fa !important;
        font-weight: 600;
        color: #495057;
    }
</style>
@endsection
