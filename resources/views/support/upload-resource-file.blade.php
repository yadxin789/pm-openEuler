@extends('layouts.app')

@section('title', '上传资料文件')

@section('content')
<h1 class="mb-4">上传资料文件</h1>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">文件上传</h5>
    </div>
    <div class="card-body">
        <form action="{{ route('support.store-resource-file') }}" method="POST" enctype="multipart/form-data">
            @csrf
            
            <div class="mb-3">
                <label for="category" class="form-label">文件分组类型 <span class="text-danger">*</span></label>
                <select class="form-select" id="category" name="category" required onchange="enableFileInput()">
                    <option value="">请选择文件分组类型</option>
                    @foreach($categories as $value => $label)
                    <option value="{{ $value }}" {{ old('category') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <small class="text-muted">用于在资料文件列表标签页中归类显示。</small>
            </div>

            <div class="mb-3">
                <label for="visibility" class="form-label">文件权限范围 <span class="text-danger">*</span></label>
                <select class="form-select" id="visibility" name="visibility" required onchange="enableFileInput()">
                    @foreach($visibilityOptions as $value => $label)
                    <option value="{{ $value }}" {{ old('visibility', 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <small class="text-muted">控制其他用户是否可以在资料文件列表中预览或下载该文件。</small>
            </div>
            
            <div class="mb-3">
                <label for="file" class="form-label">选择文件 <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="file" name="file" required {{ old('category') ? '' : 'disabled' }}>
                <small class="text-muted">请先选择文件分组类型，然后点击"打开文件"按钮选择要上传的文件</small>
            </div>
            
            <div class="alert alert-info">
                <strong>系统将自动生成以下信息：</strong>
                <ul class="mb-0 mt-2">
                    <li><strong>文件版本号：</strong>系统搜索同名文件，首次上传为v1.0，以后依次增加</li>
                    <li><strong>文件名：</strong>用户上传文件的文件名</li>
                    <li><strong>部门：</strong>当前用户所属部门（{{ auth()->user()->department->name }}）</li>
                    <li><strong>上传人：</strong>当前用户的用户名（{{ auth()->user()->name }}）</li>
                    <li><strong>权限范围：</strong>按页面选择的文件权限范围控制可见性</li>
                    <li><strong>上传时间：</strong>当前上传时的系统时间</li>
                </ul>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-upload me-2"></i>上传文件
                </button>
                <a href="{{ route('support.index') }}" class="btn btn-secondary">
                    <i class="fa fa-arrow-left me-2"></i>返回
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
function enableFileInput() {
    const category = document.getElementById('category').value;
    const visibility = document.getElementById('visibility').value;
    const fileInput = document.getElementById('file');
    
    if (category && visibility) {
        fileInput.disabled = false;
    } else {
        fileInput.disabled = true;
        fileInput.value = '';
    }
}
</script>
@endsection
