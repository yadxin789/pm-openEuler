@extends('layouts.app')

@section('title', '支援中心')

@section('content')
<h1 class="mb-4">支援中心</h1>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body text-center">
                <h3>{{ $stats['total_files'] }}</h3>
                <p class="text-muted">部门文件总数</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body text-center">
                <h3>{{ $stats['my_files'] }}</h3>
                <p class="text-muted">我上传的文件</p>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fa fa-upload me-2"></i>上传资料文件</h5>
    </div>
    <div class="card-body">
        <p class="mb-3">点击以下按钮上传资料文件到系统中。</p>
        <a href="{{ route('support.upload-resource-file') }}" class="btn btn-primary">
            <i class="fa fa-folder-open me-2"></i>打开文件
        </a>
        <small class="text-muted d-block mt-2">
            选择文件后，系统将自动生成：版本号、文件名、部门、上传人、上传时间
        </small>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h4>部门文件</h4>
        <a href="{{ route('file.upload-department') }}" class="btn btn-sm btn-primary float-right">上传文件</a>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>文件类型</th>
                    <th>文件名</th>
                    <th>版本号</th>
                    <th>权限范围</th>
                    <th>上传人</th>
                    <th>上传时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($files as $file)
                <tr>
                    <td>{{ $file->category_name }}</td>
                    <td>{{ $file->original_name }}</td>
                    <td>v{{ $file->version }}</td>
                    <td>{{ $file->visibility_name }}</td>
                    <td>{{ $file->uploader->name }}</td>
                    <td>{{ $file->created_at->format('Y-m-d') }}</td>
                    <td>
                        <a href="{{ route('file.download-department', $file) }}" class="btn btn-sm btn-primary">下载</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
