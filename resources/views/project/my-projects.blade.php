@extends('layouts.app')

@section('title', '我的项目列表')

@section('content')
<h1 class="mb-4">我的项目列表</h1>

@php
    // 属性页配置集中维护，便于四类项目使用同一套表格渲染逻辑。
    $projectTabs = [
        [
            'id' => 'overdue-projects',
            'title' => '逾期项目列表',
            'icon' => 'fa-exclamation-triangle',
            'badgeClass' => 'bg-danger',
            'headerClass' => 'bg-danger',
            'progressColor' => '#dc3545',
            'dateLabel' => '截止日期',
            'emptyText' => '暂无逾期项目',
            'projects' => $overdueProjects,
            'active' => true,
        ],
        [
            'id' => 'in-progress-projects',
            'title' => '进行中项目列表',
            'icon' => 'fa-spinner',
            'badgeClass' => 'bg-warning text-dark',
            'headerClass' => 'bg-warning',
            'progressColor' => '#0d6efd',
            'dateLabel' => '截止日期',
            'emptyText' => '暂无进行中项目',
            'projects' => $inProgressProjects,
            'active' => false,
        ],
        [
            'id' => 'completed-projects',
            'title' => '已完成项目列表',
            'icon' => 'fa-check-circle',
            'badgeClass' => 'bg-success',
            'headerClass' => 'bg-success',
            'progressColor' => '#198754',
            'dateLabel' => '完成日期',
            'emptyText' => '暂无已完成项目',
            'projects' => $completedProjects,
            'active' => false,
        ],
        [
            'id' => 'suspended-projects',
            'title' => '暂停项目列表',
            'icon' => 'fa-pause-circle',
            'badgeClass' => 'bg-secondary',
            'headerClass' => 'bg-secondary',
            'progressColor' => '#6c757d',
            'dateLabel' => '暂停日期',
            'emptyText' => '暂无暂停项目',
            'projects' => $suspendedProjects,
            'active' => false,
        ],
        [
            'id' => 'not-started-projects',
            'title' => '未开始项目列表',
            'icon' => 'fa-flag-o',
            'badgeClass' => 'bg-primary',
            'headerClass' => 'bg-primary',
            'progressColor' => '#6c757d',
            'dateLabel' => '截止日期',
            'emptyText' => '暂无未开始项目',
            'projects' => $notStartedProjects,
            'active' => false,
        ],
        [
            'id' => 'overdue-completed-projects',
            'title' => '逾期完成项目列表',
            'icon' => 'fa-calendar-check-o',
            'badgeClass' => 'bg-info text-dark',
            'headerClass' => 'bg-info',
            'progressColor' => '#0dcaf0',
            'dateLabel' => '完成日期',
            'emptyText' => '暂无逾期完成项目',
            'projects' => $overdueCompletedProjects,
            'active' => false,
        ],
    ];

    // 默认优先展示最常用的进行中项目列表
    $projectTabs = collect($projectTabs)
        ->sortBy(function ($tab) {
            return [
                'in-progress-projects' => 0,
                'overdue-projects' => 1,
                'not-started-projects' => 2,
                'completed-projects' => 3,
                'overdue-completed-projects' => 4,
                'suspended-projects' => 5,
            ][$tab['id']] ?? 99;
        })
        ->values()
        ->map(function ($tab, $index) {
            $tab['active'] = $index === 0;

            return $tab;
        });
@endphp

<ul class="nav nav-tabs mb-3" id="myProjectTabs" role="tablist">
    @foreach($projectTabs as $tab)
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $tab['active'] ? 'active' : '' }}" id="{{ $tab['id'] }}-tab" data-bs-toggle="tab" data-bs-target="#{{ $tab['id'] }}" type="button" role="tab" aria-controls="{{ $tab['id'] }}" aria-selected="{{ $tab['active'] ? 'true' : 'false' }}">
            <i class="fa {{ $tab['icon'] }} me-1"></i>{{ $tab['title'] }}
            <span class="badge {{ $tab['badgeClass'] }} ms-1">{{ $tab['projects']->count() }}</span>
        </button>
    </li>
    @endforeach
</ul>

<div class="tab-content" id="myProjectTabsContent">
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
                                    @if($tab['id'] === 'suspended-projects' && (auth()->id() == $project->creator_id || auth()->user()->isAdmin()))
                                    <form action="{{ route('project.resume', $project) }}" method="POST" class="d-inline" onsubmit="return confirm('确定恢复该项目吗？未完成任务的截止日期将自动顺延。')">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fa fa-play"></i> 重启
                                        </button>
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
