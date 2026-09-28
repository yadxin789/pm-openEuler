@extends('layouts.app')

@section('title', '知识库')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa fa-book me-2"></i>知识库</h5>
        <a href="{{ route('knowledge.create') }}" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>新建知识库</a>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('knowledge.index') }}" class="row g-2 mb-3">
            <div class="col-auto"><input type="text" name="keyword" class="form-control form-control-sm" placeholder="搜索知识库标题" value="{{ request('keyword') }}"></div>
            <div class="col-auto">
                <select name="cate_id" class="form-select form-select-sm">
                    <option value="">全部分类</option>
                    @foreach($categories as $c)
                    <option value="{{ $c->id }}" {{ request('cate_id') == $c->id ? 'selected' : '' }}>{{ $c->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <select name="scope" class="form-select form-select-sm">
                    <option value="">全部</option>
                    <option value="share" {{ request('scope') == 'share' ? 'selected' : '' }}>共享知识</option>
                    <option value="mine" {{ request('scope') == 'mine' ? 'selected' : '' }}>我的知识</option>
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-sm btn-outline-primary">搜索</button></div>
        </form>

        @if($knowledges->isEmpty())
        <p class="text-muted mb-0">暂无知识库。</p>
        @else
        <div class="row g-3">
            @foreach($knowledges as $k)
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <span class="badge {{ $k->is_share ? 'bg-success' : 'bg-secondary' }}">{{ $k->is_share ? '共享' : '个人' }}</span>
                            <span class="text-muted small">{{ $k->read }} 阅读 · {{ $k->docs_count }} 文档</span>
                        </div>
                        <h6 class="card-title mt-2 mb-1"><a href="{{ route('knowledge.show', $k) }}" class="text-decoration-none">{{ $k->title }}</a></h6>
                        @if($k->desc)<p class="card-text small text-muted">{{ \Illuminate\Support\Str::limit($k->desc, 60) }}</p>@endif
                        <small class="text-muted">{{ $k->author->name ?? '—' }}@if($k->category) · {{ $k->category->title }}@endif</small>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        {{ $knowledges->links() }}
        @endif
    </div>
</div>
@endsection