@extends('layouts.app')

@section('title', '上传文件')

@section('content')
<h1 class="mb-4">上传文件 - {{ $task->name }}</h1>

@if(session('upload_success'))
    <div class="alert alert-success">{{ session('upload_success') }}，3秒后返回我的待执行任务列表。</div>
@endif

@if(session('upload_error'))
    <div class="alert alert-danger">文件上传不成功。错误：{{ session('upload_error') }}</div>
@endif

<form method="POST" action="{{ route('task.store-file', $task) }}" enctype="multipart/form-data">
    @csrf
    <div class="mb-3">
        <label for="file" class="form-label">选择文件</label>
        <input type="file" name="file" id="file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.txt,.csv,.zip,.rar,.7z" {{ session('upload_success') ? 'disabled' : '' }}>
        <small class="text-muted">支持格式：PDF、Word、Excel、PPT、图片、文本、压缩包等，无大小限制</small>
    </div>
    <button type="submit" class="btn btn-primary" {{ session('upload_success') ? 'disabled' : '' }}>上传</button>
</form>

@if(session('upload_success') || session('upload_error'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if(session('upload_success'))
        // 上传成功后先提示用户，再延迟返回待执行任务列表。
        alert(@json(session('upload_success')));
        setTimeout(function () {
            window.location.href = @json(session('upload_redirect_url', route('task.my-tasks.pending-execution')));
        }, 3000);
    @endif

    @if(session('upload_error'))
        // 上传失败时保留在当前页面，弹出后端返回的具体错误。
        alert(@json('文件上传不成功。错误：' . session('upload_error')));
    @endif
});
</script>
@endif
@endsection