@extends('layouts.app')

@section('title', '企业微信绑定')

@section('content')
<h1 class="mb-4">企业微信管理</h1>

<div class="row">
    {{-- 绑定状态卡片 --}}
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">绑定状态</h5>
            </div>
            <div class="card-body">
                @if($isBound)
                    <div class="alert alert-success d-flex align-items-center">
                        <i class="fa fa-check-circle fa-2x me-3"></i>
                        <div>
                            <strong>已绑定企业微信</strong><br>
                            <small>企业微信用户ID: {{ $user->wecom_userid }}</small><br>
                            <small>绑定时间: {{ $user->wecom_bound_at?->format('Y-m-d H:i:s') }}</small>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <form action="{{ route('profile.wecom.unbind') }}" method="POST" onsubmit="return confirm('确定要解除企业微信绑定吗？解除后将不再接收企微通知。')">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fa fa-unlink me-1"></i> 解除绑定
                            </button>
                        </form>
                    </div>
                @else
                    @if(!$isWecomEnabled)
                        {{-- 系统尚未配置企业微信 --}}
                        <div class="alert alert-danger d-flex align-items-center">
                            <i class="fa fa-times-circle fa-2x me-3"></i>
                            <div>
                                <strong>企业微信尚未配置</strong><br>
                                <small>系统管理员尚未完成企业微信集成配置，暂时无法绑定</small>
                            </div>
                        </div>
                        @if($isAdmin)
                            <a href="{{ route('admin.wecom.settings') }}" class="btn btn-primary">
                                <i class="fa fa-cog me-1"></i> 前往配置企业微信
                            </a>
                        @else
                            <p class="text-muted mt-2 mb-0">请联系系统管理员完成企业微信配置后再进行绑定</p>
                        @endif
                    @else
                        {{-- 系统已配置但用户尚未绑定 --}}
                        <div class="alert alert-warning d-flex align-items-center">
                            <i class="fa fa-exclamation-triangle fa-2x me-3"></i>
                            <div>
                                <strong>尚未绑定企业微信</strong><br>
                                <small>绑定后可接收任务通知推送，并可使用企业微信扫码登录</small>
                            </div>
                        </div>

                        <a href="{{ route('wecom.auth', ['redirect' => route('profile.wecom.index')]) }}" class="btn btn-success">
                            <i class="fab fa-weixin me-1"></i> 绑定企业微信
                        </a>
                    @endif
                @endif
            </div>
        </div>

        {{-- 通知设置卡片 --}}
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">消息推送设置</h5>
            </div>
            <div class="card-body">
                <form action="{{ route('profile.wecom.settings.update') }}" method="POST">
                    @csrf

                    {{-- 总开关 --}}
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="notify_enabled" name="notify_enabled"
                               value="1" {{ $user->wecom_notify_enabled ? 'checked' : '' }}
                               {{ $isBound ? '' : 'disabled' }}>
                        <label class="form-check-label fw-bold" for="notify_enabled">
                            启用企业微信消息推送
                        </label>
                        @if(!$isBound)
                            <small class="text-muted d-block">请先绑定企业微信账号</small>
                        @endif
                    </div>

                    {{-- 通知类型开关 --}}
                    <div class="row">
                        @foreach($defaults as $key => $default)
                        @php
                            $labels = [
                                'task_assigned'       => '📋 任务分配通知',
                                'audit_request'       => '🔍 审核请求通知',
                                'audit_result'        => '✅ 审核结果通知',
                                'overdue_warning'     => '⚠️ 逾期提醒通知',
                                'project_started'     => '📢 项目启动通知',
                                'extension_result'    => '📅 延期申请通知',
                                'system_notification' => '📣 系统通知',
                            ];
                            $label = $labels[$key] ?? $key;
                            $checked = $userSettings[$key] ?? $default;
                        @endphp
                        <div class="col-md-6 mb-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="notify_types[]"
                                       value="{{ $key }}" id="type_{{ $key }}"
                                       {{ $checked ? 'checked' : '' }}
                                       {{ $isBound ? '' : 'disabled' }}>
                                <label class="form-check-label" for="type_{{ $key }}">{{ $label }}</label>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary" {{ $isBound ? '' : 'disabled' }}>
                            保存设置
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 右侧说明 + 消息日志 --}}
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">使用说明</h5>
            </div>
            <div class="card-body" style="font-size: 13px;">
                <ol class="ps-3 mb-0">
                    <li class="mb-2">点击「绑定企业微信」跳转到企业微信授权页面</li>
                    <li class="mb-2">在企业微信中扫描二维码完成授权确认</li>
                    <li class="mb-2">绑定成功后，任务通知将同步推送到您的企业微信</li>
                    <li class="mb-2">可在上方按需关闭特定类型的通知推送</li>
                    <li class="mb-2">每人仅能绑定一个企业微信账号</li>
                </ol>
            </div>
        </div>

        @if($recentLogs->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">最近推送记录</h5>
            </div>
            <div class="list-group list-group-flush">
                @foreach($recentLogs as $log)
                <div class="list-group-item py-2 px-3" style="font-size: 13px;">
                    <div class="d-flex justify-content-between">
                        <span>{{ \Illuminate\Support\Str::limit($log->title, 25) }}</span>
                        <span class="badge {{ $log->isSuccess() ? 'bg-success' : 'bg-danger' }}">
                            {{ $log->isSuccess() ? '✓' : '✗' }}
                        </span>
                    </div>
                    <small class="text-muted">{{ $log->created_at->format('m-d H:i') }}</small>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
