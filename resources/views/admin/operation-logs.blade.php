@extends('layouts.app')

@section('title', '操作日志')

@section('content')
<h1 class="mb-4">操作日志</h1>

<table class="table table-striped">
    <thead>
        <tr>
            <th>时间</th>
            <th>模块</th>
            <th>操作人</th>
            <th>IP地址</th>
            <th>操作</th>
            <th>内容</th>
        </tr>
    </thead>
    <tbody>
        @forelse($logs as $log)
        <tr>
            <td>{{ $log->created_at }}</td>
            <td>{{ $log->module }}</td>
            <td>{{ $log->user ? $log->user->name : '系统/未知' }}</td>
            <td>{{ $log->ip ?? '-' }}</td>
            <td>{{ $log->action }}</td>
            <td>{{ $log->content }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="6" class="text-center text-muted">暂无操作记录</td>
        </tr>
        @endforelse
    </tbody>
</table>

{{ $logs->links() }}
@endsection