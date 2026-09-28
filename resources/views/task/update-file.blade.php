@extends('layouts.app')

@section('title', '更新文件')

@section('content')
<h1 class="mb-4">更新文件 - {{ $task->name }}</h1>

@if(session('error'))
<div class="alert alert-danger">{{ session('error') }}</div>
@endif

@if($errors->any())
<div class="alert alert-danger">
    @foreach($errors->all() as $error)
    <div>{{ $error }}</div>
    @endforeach
</div>
@endif

<div class="card">
    <div class="card-header">当前文件信息</div>
    <div class="card-body">
        @php $currentFile = $task->files()->where('status', 'approved')->first(); @endphp
        @if($currentFile)
        <p><strong>文件名：</strong>{{ $currentFile->original_name }}</p>
        <p><strong>版本：</strong>V{{ $currentFile->version }}</p>
        <p><strong>上传日期：</strong>{{ $currentFile->created_at->format('Y-m-d H:i') }}</p>
        @endif
    </div>
</div>

<form method="POST" action="{{ route('task.store-update', $task) }}" enctype="multipart/form-data" class="mt-3">
    @csrf
    <div class="mb-3">
        <label for="file" class="form-label">选择新文件</label>
        <input type="file" name="file" id="file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.txt,.csv,.zip,.rar,.7z">
    </div>
    <button type="submit" class="btn btn-primary">更新文件</button>
    <a href="{{ route('task.show', $task) }}" class="btn btn-secondary">返回</a>
</form>
@endsection