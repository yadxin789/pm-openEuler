@extends('layouts.app')

@section('title', '我的' . $statusLabel . '项目列表')

@section('content')
<h1 class="mb-4">我的{{ $statusLabel }}项目列表</h1>

<!-- 过滤区域 -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="name" class="form-label">项目名称</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ request('name') }}" placeholder="输入项目名称搜索">
            </div>
            <div class="col-md-2">
                <label for="date_from" class="form-label">创建日期从</label>
                <input type="date" name="date_from" id="date_from" class="form-control" value="{{ request('date_from') }}">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">创建日期至</label>
                <input type="date" name="date_to" id="date_to" class="form-control" value="{{ request('date_to') }}">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="fa fa-search me-1"></i>搜索
                </button>
                <a href="{{ url()->current() }}" class="btn btn-outline-secondary">
                    <i class="fa fa-refresh me-1"></i>重置
                </a>
            </div>
        </form>
    </div>
</div>

<!-- 项目列表 -->
<div class="card">
    @php
        $headerClass = match($statusLabel) {
            '逾期' => 'bg-danger text-white',
            '进行中' => 'bg-warning text-white',
            '已完成' => 'bg-success text-white',
            '暂停' => 'bg-secondary text-white',
            default => 'bg-primary text-white',
        };
        $progressColor = match($statusLabel) {
            '逾期' => '#dc3545',
            '进行中' => '#0d6efd',
            '已完成' => '#198754',
            '暂停' => '#6c757d',
            default => '#0d6efd',
        };
    @endphp
    <div class="card-header {{ $headerClass }}">
        <h5 class="mb-0">{{ $statusLabel }}项目 ({{ $projects->total() }})</h5>
    </div>
    <div class="card-body">
        @if($projects->count() > 0)
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>项目名称</th>
                    <th>编码</th>
                    <th>产品型号</th>
                    <th>进度</th>
                    <th>状态</th>
                    <th>开始日期</th>
                    <th>{{ $statusLabel === '已完成' ? '完成日期' : ($statusLabel === '暂停' ? '暂停日期' : '截止日期') }}</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($projects as $project)
                <tr>
                    <td><a href="{{ route('project.show', $project) }}">{{ $project->name }}</a></td>
                    <td>{{ $project->code }}</td>
                    <td>{{ $project->product_model }}</td>
                    <td>
                        @php $pct = is_numeric($project->progress) ? max(0, (float)$project->progress) : 0; @endphp
                        <div style="width: 120px; height: 20px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden; display: inline-block; vertical-align: middle;">
                            <div style="height: 100%; width: {{ $pct }}%; background-color: {{ $progressColor }}; border-radius: 4px; transition: width 0.3s;"></div>
                        </div>
                        <small class="ms-1">{{ round($pct) }}%</small>
                    </td>
                    <td><span class="badge" style="background-color: {{ $project->status->color() }}; color: white;">{{ $project->status->label() }}</span></td>
                    <td>{{ $project->start_date->format('Y-m-d') }}</td>
                    <td>
                        @if($statusLabel === '暂停' && $project->paused_at)
                            {{ $project->paused_at->format('Y-m-d') }}
                        @else
                            {{ $project->end_date->format('Y-m-d') }}
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('project.show', $project) }}" class="btn btn-sm btn-info">查看</a>
                        @if($statusLabel === '暂停' && (auth()->id() == $project->creator_id || auth()->user()->isAdmin()))
                        <form action="{{ route('project.resume', $project) }}" method="POST" class="d-inline" onsubmit="return confirm('确定恢复该项目吗？')">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-success"><i class="fa fa-play"></i> 重启</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="d-flex justify-content-center mt-3">
            {{ $projects->appends(request()->query())->links() }}
        </div>
        @else
        <p class="text-muted text-center py-3">暂无{{ $statusLabel }}的项目</p>
        @endif
    </div>
</div>
@endsection
