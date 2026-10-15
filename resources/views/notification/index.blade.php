@extends('layouts.app')

@section('title', '通知中心')

@section('content')
<h1 class="mb-4">通知中心</h1>

<form action="{{ route('notification.mark-all-read') }}" method="POST" class="mb-3">
    @csrf
    <button type="submit" class="btn btn-primary">全部标为已读</button>
</form>

<div class="list-group">
    @foreach($notifications as $notification)
    @php
        $clickable = false;
        $clickUrl = '';
        if ($notification->type === 'private_message' && $notification->related_type === 'project' && $notification->related_id && $notification->sender_id) {
            $clickable = true;
            $clickUrl = route('messages.private', [$notification->related_id, $notification->sender_id]);
        }
    @endphp
    <div class="list-group-item {{ !$notification->is_read ? 'list-group-item-warning' : '' }} {{ $clickable ? 'cursor-pointer' : '' }}"
         @if($clickable) onclick="window.location='{{ $clickUrl }}'" style="cursor: pointer;" title="点击查看私聊" @endif>
        <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
                <h5 class="font-weight-bold">{{ $notification->title }}</h5>
                <p>{{ $notification->content }}</p>
                @if($clickable)
                <small class="text-primary"><i class="fa fa-comments me-1"></i>点击查看私聊</small>
                @endif
            </div>
            <div class="text-right ms-3">
                <small class="text-muted d-block mb-2">{{ $notification->created_at->diffForHumans() }}</small>
                <div class="btn-group-vertical">
                    @if(!$notification->is_read)
                    <form action="{{ route('notification.mark-read', $notification) }}" method="POST" class="mb-1">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">标为已读</button>
                    </form>
                    @endif
                    <form action="{{ route('notification.destroy', $notification) }}" method="POST" 
                          onsubmit="return confirm('确定要删除这条通知吗？删除后可在回收站中恢复。')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{ $notifications->links() }}
@endsection