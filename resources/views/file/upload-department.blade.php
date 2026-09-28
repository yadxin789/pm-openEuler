@extends('layouts.app')

@section('title', '上传资料文件')

@section('content')
<h1 class="mb-4">上传资料文件</h1>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">文件上传</h5>
    </div>
    <div class="card-body">
        <form action="{{ route('file.store-department') }}" method="POST" enctype="multipart/form-data">
            @csrf
            
            <div class="mb-3">
                <label for="category" class="form-label">文件分组类型 <span class="text-danger">*</span></label>
                <select class="form-select" id="category" name="category" required>
                    <option value="">请选择文件分组类型</option>
                    @foreach($categories as $value => $label)
                    <option value="{{ $value }}" {{ old('category') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <small class="text-muted">用于在资料文件列表标签页中归类显示。</small>
            </div>

            <div class="mb-3">
                <label for="visibility" class="form-label">文件权限范围 <span class="text-danger">*</span></label>
                <select class="form-select" id="visibility" name="visibility" required>
                    @foreach($visibilityOptions as $value => $label)
                    <option value="{{ $value }}" {{ old('visibility', 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <small class="text-muted">控制其他用户是否可以在资料文件列表中预览或下载该文件。</small>
            </div>
            
            <div class="mb-3">
                <label for="file" class="form-label">选择文件 <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="file" name="file" required>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">上传</button>
                <a href="{{ route('file.resource-files') }}" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</div>
@endsection
