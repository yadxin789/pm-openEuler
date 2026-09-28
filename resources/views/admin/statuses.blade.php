@extends('layouts.app')

@section('title', '状态管理')

@section('content')
<h1 class="mb-4">状态管理</h1>

<p class="text-muted mb-3">以下状态来自系统状态机定义，您可以修改各状态的显示名称（标签）。</p>

<table class="table table-striped">
    <thead>
        <tr>
            <th>状态类</th>
            <th>显示名称</th>
            <th>默认名称</th>
            <th>颜色</th>
            <th>类型</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        @foreach($statuses as $item)
        <tr>
            <td><code>{{ class_basename($item['state_class']) }}</code></td>
            <td>
                @if($item['custom_label'])
                    <span class="badge" style="background-color: {{ $item['color'] }}; color: #fff;">{{ $item['name'] }}</span>
                @else
                    <span class="badge" style="background-color: {{ $item['color'] }}; color: #fff;">{{ $item['name'] }}</span>
                    <small class="text-muted">(默认)</small>
                @endif
            </td>
            <td><span class="badge" style="background-color: {{ $item['color'] }}; color: #fff;">{{ $item['default_label'] }}</span></td>
            <td>
                <span class="badge" style="background-color: {{ $item['color'] }}; color: #fff; width: 20px; height: 20px; display: inline-block; border-radius: 3px;">&nbsp;</span>
                <code>{{ $item['color'] }}</code>
            </td>
            <td>{{ $item['type'] == 'project' ? '项目状态' : '任务状态' }}</td>
            <td>
                @php
                    $statusModel = \App\Models\Status::where('state_class', $item['state_class'])->first();
                @endphp
                @if($statusModel)
                    <a href="{{ route('admin.statuses.edit', $statusModel) }}" class="btn btn-sm btn-primary">编辑标签</a>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $statuses->links() }}
@endsection
