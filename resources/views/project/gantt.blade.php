@extends('layouts.app')

@section('content')
@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'gantt'])
<div class="row mt-1">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h3><i class="fa fa-bar-chart me-2 text-primary"></i>甘特图</h3>
            </div>
            <div class="card-body">
                <!-- 甘特图容器（带水平滚动） -->
                <div class="gantt-scroll-container">
                    <div class="gantt-container">
                    <!-- 甘特图头部 - 月份/周标题 -->
                    <div class="gantt-header">
                        <div class="gantt-task-column">任务名称</div>
                        <div class="gantt-timeline-header">
                            @php
                                $startDate = \Carbon\Carbon::parse($project->start_date);
                                $endDate = \Carbon\Carbon::parse($project->end_date);
                                $totalDays = $startDate->diffInDays($endDate) + 1;
                                
                                // 获取所有月份及其颜色
                                $months = [];
                                $currentDate = $startDate->copy();
                                $monthColors = [
                                    '#e8f4fd', // 浅蓝色 - 第1个月
                                    '#fef3c7', // 浅橙色 - 第2个月  
                                    '#d1fae5', // 浅绿色 - 第3个月
                                    '#fce7f3', // 浅粉色 - 第4个月
                                    '#f3e8ff', // 浅紫色 - 第5个月
                                    '#fed7aa', // 浅橙色 - 第6个月
                                ];
                                $monthIndex = 0;
                                $currentMonthKey = '';
                                
                                while ($currentDate <= $endDate) {
                                    $monthKey = $currentDate->format('Y-m');
                                    if ($monthKey != $currentMonthKey) {
                                        $months[$monthKey] = [
                                            'name' => $currentDate->format('Y年m月'),
                                            'color' => $monthColors[$monthIndex % count($monthColors)],
                                            'days' => 0
                                        ];
                                        $currentMonthKey = $monthKey;
                                        $monthIndex++;
                                    }
                                    $months[$monthKey]['days']++;
                                    $currentDate->addDay();
                                }
                                
                                // 预计算每天的月份颜色
                                $dayMonthColors = [];
                                $currentDate = $startDate->copy();
                                while ($currentDate <= $endDate) {
                                    $monthKey = $currentDate->format('Y-m');
                                    $dayMonthColors[] = $months[$monthKey]['color'];
                                    $currentDate->addDay();
                                }
                            @endphp
                            
                            <!-- 月份颜色图例标题 -->
                            <div class="gantt-months">
                                <div class="month-legend-title">月份标识:</div>
                                @foreach ($months as $month)
                                    <div class="month-legend-item" style="background-color: {{ $month['color'] }}">
                                        <span>{{ $month['name'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                            
                            <!-- 日期网格 -->
                            <div class="gantt-dates">
                                @for ($i = 0; $i < $totalDays; $i++)
                                    @php
                                        $date = $startDate->copy()->addDays($i);
                                        $isWeekend = $date->isWeekend();
                                        $monthColor = $dayMonthColors[$i];
                                    @endphp
                                    <div class="gantt-date {{ $isWeekend ? 'weekend' : '' }}" 
                                         style="background-color: {{ $monthColor }}"
                                         title="{{ $date->format('Y-m-d') }}">
                                        <span class="date-day">{{ $date->format('d') }}</span>
                                        <span class="date-weekday">{{ $date->format('D') }}</span>
                                    </div>
                                @endfor
                            </div>
                        </div>
                    </div>
                    
                    <!-- 甘特图内容 -->
                    <div class="gantt-body">
                        @php
                            // 按部门分组任务
                            $tasksByDept = [];
                            foreach ($tasks as $task) {
                                $deptId = $task->department_id ?? 0;
                                $tasksByDept[$deptId][] = $task;
                            }
                        @endphp
                        @foreach ($participatingDepts as $dept)
                            @php $deptTasks = $tasksByDept[$dept->id] ?? []; @endphp
                            @if(count($deptTasks) > 0)
                            <!-- 部门标题行 -->
                            <div class="gantt-row gantt-dept-header">
                                <div class="gantt-task-column" style="font-weight: 700; color: #1a56db; background-color: #eff6ff;">
                                    <i class="fa fa-building me-1"></i>{{ $dept->name }}
                                    <span class="badge bg-primary ms-2">{{ count($deptTasks) }}个任务</span>
                                </div>
                                <div class="gantt-timeline">
                                    <div class="gantt-grid">
                                        @for ($i = 0; $i < $totalDays; $i++)
                                            <div class="gantt-grid-cell" style="background-color: #eff6ff;"></div>
                                        @endfor
                                    </div>
                                </div>
                            </div>
                            @foreach ($deptTasks as $task)
                                @php
                                    $taskStart = $task->start_date ? \Carbon\Carbon::parse($task->start_date) : $startDate;
                                    $taskEnd = \Carbon\Carbon::parse($task->end_date);
                                    $taskStartOffset = $startDate->diffInDays($taskStart);
                                    $taskDuration = $taskStart->diffInDays($taskEnd) + 1;
                                    
                                    if ($taskStartOffset < 0) {
                                        $taskDuration += $taskStartOffset;
                                        $taskStartOffset = 0;
                                    }
                                    if ($taskStartOffset + $taskDuration > $totalDays) {
                                        $taskDuration = $totalDays - $taskStartOffset;
                                    }
                                @endphp
                                
                                <div class="gantt-row">
                                    <div class="gantt-task-column">
                                        <div class="task-info">
                                            <strong class="task-name">{{ $task->name }}</strong>
                                            <div class="task-meta">
                                                <span class="task-assignee">
                                                    <i class="fa fa-user mr-1"></i>{{ $task->executor->name ?? '未分配' }}
                                                </span>
                                                <span class="task-status" style="background-color: {{ $task->status->color() }}">
                                                    {{ $task->status->label() }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="gantt-timeline">
                                        <!-- 网格线背景 -->
                                        <div class="gantt-grid">
                                            @for ($i = 0; $i < $totalDays; $i++)
                                                <div class="gantt-grid-cell"></div>
                                            @endfor
                                        </div>
                                        
                                        <!-- 任务条 -->
                                        <div 
                                            class="gantt-bar"
                                            style="left: {{ ($taskStartOffset / $totalDays) * 100 }}%; width: {{ ($taskDuration / $totalDays) * 100 }}%; background-color: {{ $task->status->color() }}"
                                            title="任务: {{ $task->name }} | 负责人: {{ $task->executor->name ?? '未分配' }} | 状态: {{ $task->status->label() }} | 起始: {{ $task->start_date ? $task->start_date->format('Y-m-d') : '未设置' }} | 截止: {{ $task->end_date ? $task->end_date->format('Y-m-d') : '未设置' }}">
                                            <div class="gantt-bar-content">
                                                {{ $task->name }}
                                            </div>
                                            <div class="gantt-bar-start">●</div>
                                            <div class="gantt-bar-end">●</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                            @endif
                        @endforeach
                    </div>
                </div>
                
                </div> <!-- 关闭 gantt-container -->
                </div> <!-- 关闭 gantt-scroll-container -->
                
                <!-- 图例 -->
                    <div class="card">
                        <div class="card-header">图例说明</div>
                        <div class="card-body">
                            <div class="legend-container">
                                <div class="legend-item">
                                    <div class="legend-color" style="background-color: #6c757d"></div>
                                    <span>等待 - 任务未开始</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color" style="background-color: #17a2b8"></div>
                                    <span>剩余N天 - 任务进行中</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color" style="background-color: #ffc107"></div>
                                    <span>等待审核 - 文件已提交待审核</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color" style="background-color: #28a745"></div>
                                    <span>已完成 - 任务已完成</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color" style="background-color: #dc3545"></div>
                                    <span>超期N天 - 任务已超期</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 项目时间信息 -->
                <div class="mt-4 alert alert-info">
                    <strong>项目时间范围：</strong>{{ $project->start_date->format('Y年m月d日') }} 至 {{ $project->end_date->format('Y年m月d日') }}
                    <span class="ml-4"><strong>总天数：</strong>{{ $totalDays }} 天</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.gantt-scroll-container {
    overflow-x: auto;
    overflow-y: hidden;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    margin-bottom: 10px;
}

.gantt-container {
    min-width: max-content;
    background: #fff;
}

/* 头部样式 */
.gantt-header {
    display: flex;
    background-color: #4a5568;
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.gantt-task-column {
    width: 220px;
    min-width: 220px;
    padding: 12px 15px;
    border-right: 2px solid #2d3748;
    font-weight: 600;
    font-size: 14px;
}

.gantt-timeline-header {
    flex: 1;
    min-width: 0;
}

.gantt-months {
    display: flex;
    align-items: center;
    background-color: #2d3748;
    padding: 8px 12px;
    gap: 12px;
}

.month-legend-title {
    font-size: 12px;
    font-weight: 600;
    color: #a0aec0;
}

.month-legend-item {
    display: flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 4px;
    border: 1px solid rgba(255,255,255,0.1);
}

.month-legend-item span {
    font-size: 11px;
    font-weight: 500;
    color: #2d3748;
}

.gantt-dates {
    display: flex;
    border-bottom: 2px solid #2d3748;
}

.gantt-date {
    flex: 1;
    min-width: 35px;
    padding: 8px 4px;
    text-align: center;
    border-right: 1px solid rgba(0,0,0,0.05);
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.gantt-date.weekend {
    opacity: 0.7;
}

.date-day {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #4a5568;
}

.date-weekday {
    display: block;
    font-size: 10px;
    color: #a0aec0;
}

/* 内容区域 */
.gantt-body {
    max-height: 500px;
    overflow-y: auto;
}

.gantt-row {
    display: flex;
    border-bottom: 1px solid #e2e8f0;
    min-height: 50px;
    align-items: center;
}

.gantt-row:hover {
    background-color: #f7fafc;
}

.gantt-dept-header {
    min-height: 40px;
    border-bottom: 2px solid #93c5fd;
}

.gantt-dept-header .gantt-task-column {
    font-size: 14px;
    padding: 8px 15px;
}

.gantt-row:nth-child(even) {
    background-color: #fff;
}

.gantt-row:nth-child(odd) {
    background-color: #fafafa;
}

.gantt-row .gantt-task-column {
    background-color: inherit;
    border-right: 1px solid #e2e8f0;
    color: #2d3748;
    padding: 10px 15px;
}

.task-info {
    display: flex;
    flex-direction: column;
}

.task-name {
    font-size: 13px;
    color: #2d3748;
    margin-bottom: 4px;
}

.task-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.task-assignee {
    font-size: 11px;
    color: #718096;
}

.task-status {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 3px;
    color: white;
}

/* 时间线区域 */
.gantt-timeline {
    flex: 1;
    position: relative;
    min-height: 50px;
    padding: 5px 0;
}

.gantt-grid {
    display: flex;
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
}

.gantt-grid-cell {
    flex: 1;
    border-right: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}

/* 任务条样式 */
.gantt-bar {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    border-radius: 4px;
    padding: 6px 8px;
    min-height: 28px;
    display: flex;
    align-items: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
    cursor: pointer;
}

.gantt-bar:hover {
    transform: translateY(-50%) scale(1.02);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.gantt-bar-content {
    color: white;
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

.gantt-bar-start,
.gantt-bar-end {
    font-size: 8px;
    color: rgba(255, 255, 255, 0.8);
    margin: 0 2px;
}

/* 图例 */
.legend-container {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.legend-color {
    width: 20px;
    height: 12px;
    border-radius: 3px;
}

/* 滚动条样式 */
.gantt-body::-webkit-scrollbar {
    width: 6px;
}

.gantt-body::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.gantt-body::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.gantt-body::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>
@endsection