{{--
    项目属性页（Tab）统一导航条
    用法：@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'overview'])
    可用 activeTab 值：overview / gantt / history / logs / collab / chat
--}}
@php
    $projectTabs = [
        'overview' => ['label' => '项目概览', 'route' => route('project.show', $project), 'icon' => 'fa-tachometer'],
        'gantt'    => ['label' => '甘特图',   'route' => route('project.gantt', $project),       'icon' => 'fa-bar-chart'],
        'history'  => ['label' => '项目履历', 'route' => route('history.project.history', $project), 'icon' => 'fa-history'],
        'logs'     => ['label' => '项目日志', 'route' => route('project.logs', $project),        'icon' => 'fa-list-alt'],
        'collab'   => ['label' => '协同办公', 'route' => route('project.collab.index', $project), 'icon' => 'fa-users'],
        'chat'     => ['label' => '聊天室',   'route' => route('messages.index', $project->id),  'icon' => 'fa-comments'],
    ];
    $activeTab = $activeTab ?? 'overview';
@endphp
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3 mb-2">
    <div>
        <h4 class="mb-0">
            <i class="fa fa-folder-open text-primary me-2"></i>{{ $project->name }}
        </h4>
        <span class="text-muted small">
            项目编码：{{ $project->code }}
            · 状态：
            <span class="badge" style="background-color: {{ $project->status->color() }}; color: white;">
                {{ $project->status->label() }}
            </span>
        </span>
    </div>
</div>
<ul class="nav nav-tabs mb-3" style="border-bottom: 2px solid #dee2e6;">
    @foreach($projectTabs as $key => $tab)
    <li class="nav-item">
        <a class="nav-link {{ $activeTab === $key ? 'active fw-bold' : '' }}"
           style="{{ $activeTab === $key ? 'background-color:#fff; border-color:#dee2e6 #dee2e6 #fff; color:#0d6efd;' : 'color:#495057;' }}"
           href="{{ $tab['route'] }}">
            <i class="fa {{ $tab['icon'] }} me-1"></i>{{ $tab['label'] }}
        </a>
    </li>
    @endforeach
</ul>
