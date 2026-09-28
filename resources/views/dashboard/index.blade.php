@extends('layouts.app')

@section('content')
<div class="row mt-4">
    <div class="col-md-12">
        <h2>{{ $currentYear }}年度项目概览</h2>
        <p class="text-muted">我的项目数据看板</p>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5>📊 月度参与项目趋势</h5>
            </div>
            <div class="card-body">
                <div id="monthlyChart" style="height: 300px;"></div>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5>📈 项目状态分布</h5>
            </div>
            <div class="card-body">
                <div id="statusChart" style="height: 300px;"></div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="card-title text-muted">参与项目</h5>
                        <h3 class="text-primary">{{ $projectStats->total ?? 0 }}</h3>
                    </div>
                    <i class="fa fa-folder-open fa-3x text-primary opacity-30"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="card-title text-muted">正常结案</h5>
                        <h3 class="text-success">{{ $projectStats->completed_on_time ?? 0 }}</h3>
                    </div>
                    <i class="fa fa-check-circle fa-3x text-success opacity-30"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="card-title text-muted">延期结案</h5>
                        <h3 class="text-warning">{{ $projectStats->completed_delayed ?? 0 }}</h3>
                    </div>
                    <i class="fa fa-clock-o fa-3x text-warning opacity-30"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="card-title text-muted">进行中</h5>
                        <h3 class="text-info">{{ $projectStats->in_progress ?? 0 }}</h3>
                    </div>
                    <i class="fa fa-spinner fa-3x text-info opacity-30"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5>⚡ 我的效率指标</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2">
                        <div class="text-center p-3 border rounded">
                            <h3 class="text-primary">{{ $efficiencyStats['task_count'] }}</h3>
                            <p class="text-muted mb-0 small">任务总数</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-center p-3 border rounded">
                            <h3 class="text-success">{{ $efficiencyStats['completed_tasks'] }}</h3>
                            <p class="text-muted mb-0 small">已完成</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-center p-3 border rounded">
                            <h3 class="text-warning">{{ $efficiencyStats['pending_tasks'] }}</h3>
                            <p class="text-muted mb-0 small">进行中</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-center p-3 border rounded {{ $efficiencyStats['overdue_tasks'] > 0 ? 'border-danger' : '' }}">
                            <h3 class="{{ $efficiencyStats['overdue_tasks'] > 0 ? 'text-danger' : 'text-muted' }}">
                                {{ $efficiencyStats['overdue_tasks'] }}
                            </h3>
                            <p class="text-muted mb-0 small">超期任务</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-center p-3 border rounded">
                            <h3 class="text-info">{{ $efficiencyStats['task_completion_rate'] }}%</h3>
                            <p class="text-muted mb-0 small">完成率</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-center p-3 border rounded">
                            <h3 class="text-primary">{{ $efficiencyStats['total_files'] }}</h3>
                            <p class="text-muted mb-0 small">已上传文件</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="card-title text-muted">暂停项目</h5>
                        <h3 class="text-secondary">{{ $projectStats->paused ?? 0 }}</h3>
                    </div>
                    <i class="fa fa-pause-circle fa-3x text-secondary opacity-30"></i>
                </div>
            </div>
        </div>
    </div>
</div>


@endsection

@section('scripts')
<script>
$(document).ready(function() {
    var monthlyChart = echarts.init(document.getElementById('monthlyChart'));
    var months = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
    var monthlyData = @json($monthlyProjectData);

    monthlyChart.setOption({
        title: {
            text: '参与项目趋势'
        },
        tooltip: {
            trigger: 'axis'
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '3%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: months
        },
        yAxis: {
            type: 'value'
        },
        series: [{
            name: '项目数量',
            type: 'bar',
            data: Object.values(monthlyData),
            itemStyle: {
                color: '#764ba2'
            }
        }]
    });

    var statusChart = echarts.init(document.getElementById('statusChart'));
    var statusData = @json($projectStatusData);

    statusChart.setOption({
        title: {
            text: '项目状态分布',
            left: 'center',
            top: 10
        },
        tooltip: {
            trigger: 'item'
        },
        legend: {
            orient: 'horizontal',
            bottom: 0,
            itemGap: 15,
            textStyle: {
                fontSize: 11
            }
        },
        series: [{
            name: '状态',
            type: 'pie',
            radius: ['45%', '75%'],
            center: ['50%', '45%'],
            avoidLabelOverlap: true,
            itemStyle: {
                borderRadius: 10,
                borderColor: '#fff',
                borderWidth: 2
            },
            label: {
                show: true,
                formatter: '{b}: {c}',
                position: 'outside',
                distance: 10,
                fontSize: 11
            },
            labelLine: {
                show: true,
                length: 15,
                length2: 10
            },
            data: [
                {value: statusData['正常结案'] || 0, name: '正常结案', itemStyle: {color: '#52c41a'}},
                {value: statusData['延期结案'] || 0, name: '延期结案', itemStyle: {color: '#faad14'}},
                {value: statusData['进行中'] || 0, name: '进行中', itemStyle: {color: '#1890ff'}},
                {value: statusData['暂停'] || 0, name: '暂停', itemStyle: {color: '#722ed1'}}
            ]
        }]
    });
    window.addEventListener('resize', function() {
        monthlyChart.resize();
        statusChart.resize();
    });
});
</script>
@endsection