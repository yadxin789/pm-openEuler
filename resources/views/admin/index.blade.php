@extends('layouts.app')

@section('title', '管理面板')

@section('content')
<h1 class="mb-4">管理面板</h1>

<div class="row">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3>{{ $stats['projects'] }}</h3>
                <p class="text-muted">项目总数</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3>{{ $stats['users'] }}</h3>
                <p class="text-muted">用户总数</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3>{{ $stats['files'] }}</h3>
                <p class="text-muted">文件总数</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3>{{ $stats['tasks'] }}</h3>
                <p class="text-muted">任务总数</p>
            </div>
        </div>
    </div>
</div>

<div class="mt-4">
    <div class="card">
        <div class="card-header">
            <h4>快速导航</h4>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-2">
                    <a href="{{ route('admin.users') }}" class="btn w-100 btn-primary">用户管理</a>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.departments') }}" class="btn w-100 btn-secondary">部门管理</a>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.roles') }}" class="btn w-100 btn-info">角色管理</a>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.statuses') }}" class="btn w-100 btn-warning">状态管理</a>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.task-types') }}" class="btn w-100 btn-info">任务类型</a>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.file-repository') }}" class="btn w-100 btn-danger">文件库</a>
                </div>
                <div class="col-md-2 mt-3">
                    <a href="{{ route('admin.chat-messages') }}" class="btn w-100 btn-secondary">聊天记录</a>
                </div>
                <div class="col-md-2 mt-3">
                    <a href="{{ route('admin.wecom.settings') }}" class="btn w-100 btn-success">企业微信</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection