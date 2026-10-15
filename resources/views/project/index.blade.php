@extends('layouts.app')

@php
    // 项目监督人复用项目管理页，只调整页面标题显示。
    $pageTitle = auth()->user()->isProjectManager() && !auth()->user()->isAdmin() ? '全部项目列表' : '项目管理';
@endphp

@section('title', $pageTitle)

@section('content')
<h1 class="mb-4">{{ $pageTitle }}</h1>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <h3>{{ $stats['total'] }}</h3>
                <p>全部项目</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body text-center">
                <h3>{{ $stats['in_progress'] }}</h3>
                <p>进行中</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <h3>{{ $stats['completed'] }}</h3>
                <p>已完成</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body text-center">
                <h3>{{ $stats['overdue'] }}</h3>
                <p>已逾期</p>
            </div>
        </div>
    </div>
</div>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-secondary text-white">
            <div class="card-body text-center">
                <h3>{{ $stats['suspended'] }}</h3>
                <p>已暂停</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <h3>{{ $stats['overdue_completed'] }}</h3>
                <p>逾期完成</p>
            </div>
        </div>
    </div>
</div>

@if(auth()->user()->isProjectCreator() || auth()->user()->isAdmin())
<a href="{{ route('project.create') }}" class="btn btn-primary mb-3">创建项目</a>
@endif

@php
    // 全部项目列表按状态放入标签页，表格和操作按钮使用同一套渲染逻辑。
    $projectTabs = [
        [
            'id' => 'overdue-projects',
            'title' => '已逾期项目',
            'icon' => 'fa-exclamation-triangle',
            'badgeClass' => 'bg-danger',
            'headerClass' => 'bg-danger',
            'progressColor' => '#dc3545',
            'dateLabel' => '截止日期',
            'emptyText' => '暂无已逾期的项目',
            'projects' => $overdueProjects,
            'manageable' => true,
            'active' => true,
        ],
        [
            'id' => 'in-progress-projects',
            'title' => '进行中项目',
            'icon' => 'fa-spinner',
            'badgeClass' => 'bg-warning text-dark',
            'headerClass' => 'bg-warning',
            'progressColor' => '#0d6efd',
            'dateLabel' => '截止日期',
            'emptyText' => '暂无进行中的项目',
            'projects' => $inProgressProjects,
            'manageable' => true,
            'active' => false,
        ],
        [
            'id' => 'completed-projects',
            'title' => '已完成项目',
            'icon' => 'fa-check-circle',
            'badgeClass' => 'bg-success',
            'headerClass' => 'bg-success',
            'progressColor' => '#198754',
            'dateLabel' => '完成日期',
            'emptyText' => '暂无已完成的项目',
            'projects' => $completedProjects,
            'manageable' => false,
            'active' => false,
        ],
        [
            'id' => 'suspended-projects',
            'title' => '已暂停项目',
            'icon' => 'fa-pause-circle',
            'badgeClass' => 'bg-secondary',
            'headerClass' => 'bg-secondary',
            'progressColor' => '#6c757d',
            'dateLabel' => '暂停日期',
            'emptyText' => '暂无已暂停的项目',
            'projects' => $suspendedProjects,
            'manageable' => false,
            'active' => false,
        ],
        [
            'id' => 'overdue-completed-projects',
            'title' => '逾期完成项目',
            'icon' => 'fa-calendar-check-o',
            'badgeClass' => 'bg-info text-dark',
            'headerClass' => 'bg-info',
            'progressColor' => '#0dcaf0',
            'dateLabel' => '完成日期',
            'emptyText' => '暂无逾期完成的项目',
            'projects' => $overdueCompletedProjects,
            'manageable' => false,
            'active' => false,
        ],
    ];
@endphp

<ul class="nav nav-tabs mb-3" id="allProjectTabs" role="tablist">
    @foreach($projectTabs as $tab)
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $tab['active'] ? 'active' : '' }}" id="{{ $tab['id'] }}-tab" data-bs-toggle="tab" data-bs-target="#{{ $tab['id'] }}" type="button" role="tab" aria-controls="{{ $tab['id'] }}" aria-selected="{{ $tab['active'] ? 'true' : 'false' }}">
            <i class="fa {{ $tab['icon'] }} me-1"></i>{{ $tab['title'] }}
            <span class="badge {{ $tab['badgeClass'] }} ms-1">{{ $tab['projects']->count() }}</span>
        </button>
    </li>
    @endforeach
</ul>

<div class="tab-content" id="allProjectTabsContent">
    @foreach($projectTabs as $tab)
    <div class="tab-pane fade {{ $tab['active'] ? 'show active' : '' }}" id="{{ $tab['id'] }}" role="tabpanel" aria-labelledby="{{ $tab['id'] }}-tab">
        <div class="card mb-4">
            <div class="card-header {{ $tab['headerClass'] }} text-white">
                <h5 class="mb-0"><i class="fa {{ $tab['icon'] }} me-2"></i>{{ $tab['title'] }} ({{ $tab['projects']->count() }})</h5>
            </div>
            <div class="card-body">
                @if($tab['projects']->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th>项目名称</th>
                                <th>编码</th>
                                <th>产品型号</th>
                                <th>进度</th>
                                <th>状态</th>
                                <th>开始日期</th>
                                <th>{{ $tab['dateLabel'] }}</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tab['projects'] as $project)
                            <tr>
                                <td><a href="{{ route('project.show', $project) }}">{{ $project->name }}</a></td>
                                <td>{{ $project->code }}</td>
                                <td>{{ $project->product_model }}</td>
                                <td>
                                    @php $pct = is_numeric($project->progress) ? max(0, (float)$project->progress) : 0; @endphp
                                    <div style="width: 120px; height: 20px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden; display: inline-block; vertical-align: middle;">
                                        <div style="height: 100%; width: {{ $pct }}%; background-color: {{ $tab['progressColor'] }}; border-radius: 4px; transition: width 0.3s;"></div>
                                    </div>
                                    <small class="ms-1">{{ round($pct) }}%</small>
                                </td>
                                <td><span class="badge" style="background-color: {{ $project->status->color() }}; color: white;">{{ $project->status->label() }}</span></td>
                                <td>{{ $project->start_date->format('Y-m-d') }}</td>
                                <td>
                                    @if($tab['id'] === 'suspended-projects')
                                        {{ $project->paused_at ? $project->paused_at->format('Y-m-d') : '-' }}
                                    @else
                                        {{ $project->end_date->format('Y-m-d') }}
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('project.show', $project) }}" class="btn btn-sm btn-info">查看</a>
                                    @if($tab['manageable'] && (auth()->user()->isProjectCreator() || auth()->user()->isAdmin()))
                                    <a href="{{ route('project.edit', $project) }}" class="btn btn-sm btn-primary">编辑</a>
                                    <form action="{{ route('project.destroy', $project) }}" method="POST" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除该项目吗？删除后可在回收站中恢复。')">删除</button>
                                    </form>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <p class="text-muted text-center py-3">{{ $tab['emptyText'] }}</p>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endsection
