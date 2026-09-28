@extends('layouts.app')

@section('title', '编辑状态标签')

@section('content')
<h1 class="mb-4">编辑状态标签</h1>

<div class="card">
    <div class="card-body">
        <div class="mb-4">
            <h5>状态信息</h5>
            <table class="table table-bordered w-auto">
                <tr>
                    <th>状态类</th>
                    <td><code>{{ $status->state_class }}</code></td>
                </tr>
                <tr>
                    <th>类型</th>
                    <td>{{ str_contains($status->state_class, '\\Project\\') ? '项目状态' : '任务状态' }}</td>
                </tr>
                <tr>
                    <th>默认名称</th>
                    <td>
                        <span class="badge" style="background-color: {{ $defaultColor }}; color: #fff;">
                            {{ $defaultLabel }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>默认颜色</th>
                    <td>
                        <span class="badge" style="background-color: {{ $defaultColor }}; color: #fff; width: 20px; height: 20px; display: inline-block; border-radius: 3px;">&nbsp;</span>
                        <code>{{ $defaultColor }}</code>
                    </td>
                </tr>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.statuses.update', $status) }}">
            @csrf
            @method('PUT')
            <div class="mb-3">
                <label for="custom_label" class="form-label">自定义标签名</label>
                <input type="text" name="custom_label" id="custom_label" class="form-control"
                       value="{{ $status->custom_label }}"
                       placeholder="{{ $defaultLabel }}（留空则使用默认名称）">
                <div class="form-text">设置后将覆盖系统默认的"{{ $defaultLabel }}"显示名称。留空则恢复默认。</div>
            </div>
            <button type="submit" class="btn btn-primary">保存</button>
            <a href="{{ route('admin.statuses') }}" class="btn btn-secondary">取消</a>
        </form>
    </div>
</div>
@endsection
