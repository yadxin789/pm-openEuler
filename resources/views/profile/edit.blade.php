@extends('layouts.app')

@section('title', '基本资料')

@section('content')
<div class="container">
    <h1 class="mb-4">基本资料</h1>

    <div class="card">
        <div class="card-body">
            {{-- 头像区 --}}
            <div class="d-flex align-items-center mb-4">
                <div class="avatar-preview">
                    @if($user->avatar)
                        @php
                            $avatarPath = str_starts_with($user->avatar, 'defaults/')
                                ? 'avatars/' . $user->avatar
                                : 'storage/avatars/' . $user->avatar;
                        @endphp
                        <img src="{{ asset($avatarPath) }}"
                             alt="当前头像"
                             class="rounded-circle"
                             style="width: 90px; height: 90px; object-fit: cover; border: 2px solid #e9ecef;">
                    @else
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-secondary text-white"
                             style="width: 90px; height: 90px; font-size: 36px;">
                            <span>{{ mb_substr($user->name, 0, 1) }}</span>
                        </div>
                    @endif
                </div>
                <div class="ms-3">
                    <h5 class="mb-1">{{ $user->name }}</h5>
                    <p class="text-muted mb-2">{{ $user->role ? $user->role->name : '未知角色' }}</p>
                    <a href="{{ route('profile.avatar') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-image"></i> 更换头像
                    </a>
                </div>
            </div>

            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                <table class="table table-bordered form-table mb-0">
                    <tr>
                        <td class="form-table-label">用户名</td>
                        <td>{{ $user->username }}</td>
                        <td class="form-table-label">姓名<font color="red">*</font></td>
                        <td>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" placeholder="请输入姓名" maxlength="50" required>
                            @error('name')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </td>
                    </tr>
                    <tr>
                        <td class="form-table-label">所在部门</td>
                        <td>{{ $user->department ? $user->department->name : '未分配' }}</td>
                        <td class="form-table-label">角色</td>
                        <td>{{ $user->role ? $user->role->name : '未分配' }}</td>
                    </tr>
                    <tr>
                        <td class="form-table-label">个人简介</td>
                        <td colspan="3">
                            <textarea name="bio" rows="4" class="form-control" placeholder="请输入个人简介" maxlength="500">{{ old('bio', $user->bio) }}</textarea>
                            @error('bio')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </td>
                    </tr>
                </table>
                <div class="pt-4">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> 立即提交</button>
                    <button type="reset" class="btn btn-outline-secondary">重置</button>
                </div>
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
