@extends('layouts.app')

@section('title', '发送通知')

@section('content')
<h1 class="mb-4">发送系统通知</h1>

<form method="POST" action="{{ route('admin.notification.post') }}">
    @csrf
    <div class="mb-3">
        <label for="title" class="form-label">通知标题</label>
        <input type="text" name="title" id="title" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="content" class="form-label">通知内容</label>
        <textarea name="content" id="content" class="form-control" rows="5" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">发送通知</button>
</form>
@endsection