@extends('layouts.app')

@section('title', '申请延期')

@section('content')
<h1 class="mb-4">申请延期 - {{ $task->name }}</h1>

<form method="POST" action="{{ route('task.store-extension', $task) }}">
    @csrf
    <div class="mb-3">
        <label for="days" class="form-label">延期天数</label>
        <input type="number" name="days" id="days" class="form-control" min="1" required>
    </div>
    <div class="mb-3">
        <label for="reason" class="form-label">延期原因</label>
        <textarea name="reason" id="reason" class="form-control" rows="3" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">提交申请</button>
</form>
@endsection