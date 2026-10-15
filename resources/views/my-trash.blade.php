@extends('layouts.app')

@section('title', '我的回收站')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><i class="fa fa-trash me-2"></i>我的回收站</h1>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>返回首页
        </a>
    </div>

    <div class="alert alert-info">
        <i class="fa fa-info-circle me-1"></i>
        这里只显示<strong>您自己删除的</strong>或<strong>您创建的</strong>内容。恢复后内容会回到原来的位置。
        被管理员彻底删除的内容不在此列。
        <br>
        <i class="fa fa-exclamation-triangle me-1"></i>
        回收站内容仅保留 <strong>{{ config('pm.trash_retention_days', 15) }} 天</strong>，逾期系统会自动彻底删除且无法恢复，请及时恢复需要保留的内容。
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        @foreach($types as $key => $meta)
            <li class="nav-item">
                <a class="nav-link {{ $type === $key ? 'active' : '' }}"
                   href="{{ route('my-trash', ['type' => $key]) }}">
                    <i class="fa {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}
                    <span class="badge {{ ($counts[$key] ?? 0) > 0 ? 'bg-danger' : 'bg-secondary' }}">{{ $counts[$key] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fa {{ $types[$type]['icon'] }} me-2"></i>{{ $types[$type]['label'] }}
                <span class="text-muted small ms-2">共 {{ $items->total() }} 条</span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:80px;">ID</th>
                            <th>名称</th>
                            <th style="width:150px;">来源</th>
                            <th style="width:190px;">删除时间</th>
                            <th style="width:140px;" class="text-end">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="text-muted">#{{ $row['id'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>
                                    @if($row['deleted_by_me'])
                                        <span class="badge bg-secondary">我删除的</span>
                                    @else
                                        <span class="badge bg-warning text-dark">我创建的</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $row['deleted_at'] ? $row['deleted_at']->format('Y-m-d H:i:s') : '-' }}</td>
                                <td class="text-end">
                                    <form action="{{ route('my-trash.restore', ['type' => $type, 'id' => $row['id']]) }}"
                                          method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fa fa-undo me-1"></i>恢复
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    回收站中暂无{{ $types[$type]['label'] }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($items->hasPages())
            <div class="card-footer">
                {{ $items->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
