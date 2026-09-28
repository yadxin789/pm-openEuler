@extends('layouts.app')

@section('title', '企业微信配置')

@section('content')
<h1 class="mb-4">企业微信集成配置</h1>

<div class="row">
    {{-- 配置表单 --}}
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">应用配置</h5>
                <span class="badge {{ $config ? 'bg-success' : 'bg-secondary' }}">
                    {{ $config ? '已配置' : '未配置' }}
                </span>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.wecom.settings.save') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">企业ID (CorpID)</label>
                        <input type="text" name="corp_id" class="form-control" value="{{ old('corp_id', $config->corp_id ?? '') }}" placeholder="ww1234567890abcdef" required>
                        <small class="text-muted">企业微信管理后台 → 我的企业 → 企业信息</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">应用 AgentId</label>
                        <input type="text" name="agent_id" class="form-control" value="{{ old('agent_id', $config->agent_id ?? '') }}" placeholder="1000002" required>
                        <small class="text-muted">企业微信管理后台 → 应用管理 → 自建应用 → AgentId</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">应用 Secret</label>
                        <input type="password" name="secret" class="form-control" value="{{ old('secret', $config->secret ?? '') }}" placeholder="******" required>
                        <small class="text-muted">企业微信管理后台 → 应用管理 → 自建应用 → Secret（需妥善保管）</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">回调 Token</label>
                        <input type="text" name="token" class="form-control" value="{{ old('token', $config->token ?? '') }}" placeholder="自定义Token">
                        <small class="text-muted">与企微后台「接收消息」设置的 Token 一致</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">回调 EncodingAESKey</label>
                        <input type="text" name="encoding_aes_key" class="form-control" value="{{ old('encoding_aes_key', $config->encoding_aes_key ?? '') }}" placeholder="43位字符">
                        <small class="text-muted">与企微后台「接收消息」设置的 EncodingAESKey 一致</small>
                    </div>
                    <button type="submit" class="btn btn-primary">保存配置</button>

                    <a href="{{ route('admin.wecom.test') }}" class="btn btn-outline-info ms-2"
                       onclick="return confirm('将向全员发送一条测试消息，确定？')">
                        测试连接
                    </a>
                </form>
            </div>
        </div>
    </div>

    {{-- 使用说明 + 绑定统计 --}}
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">绑定情况</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <h3 class="text-success">{{ $stat['bound'] }}</h3>
                        <small class="text-muted">已绑定用户</small>
                    </div>
                    <div class="col-6">
                        <h3 class="text-secondary">{{ $stat['total'] - $stat['bound'] }}</h3>
                        <small class="text-muted">未绑定用户</small>
                    </div>
                </div>
                <hr>
                <div class="text-center">
                    <small class="text-muted">用户总数: {{ $stat['total'] }} | 日志数: {{ $logCount }}</small>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">配置说明</h5>
            </div>
            <div class="card-body" style="font-size: 13px;">
                <ol class="ps-3 mb-0">
                    <li class="mb-2">登录 <a href="https://work.weixin.qq.com/" target="_blank">企业微信管理后台</a></li>
                    <li class="mb-2">在 "应用管理" 中创建一个自建应用</li>
                    <li class="mb-2">在 "网页授权及JS-SDK" 中设置可信域名</li>
                    <li class="mb-2">将下方回调 URL 填入 "接收消息" 设置</li>
                    <li class="mb-2">保存配置后点击「测试连接」</li>
                </ol>
                <hr>
                <label class="form-label fw-bold">回调 URL（复制到企微后台）:</label>
                <div class="input-group">
                    <input type="text" class="form-control form-control-sm" id="callbackUrl"
                           value="{{ url('/wecom/callback/event') }}" readonly>
                    <button class="btn btn-sm btn-outline-secondary" onclick="copyCallbackUrl()">复制</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 最近消息日志 --}}
@if($stat['logs']->isNotEmpty())
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">最近消息发送日志</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>用户</th>
                    <th>类型</th>
                    <th>标题</th>
                    <th>结果</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stat['logs'] as $log)
                <tr>
                    <td>{{ $log->created_at->format('m-d H:i') }}</td>
                    <td>{{ $log->user->name ?? '-' }}</td>
                    <td>{{ $log->msg_type }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($log->title, 30) }}</td>
                    <td>
                        <span class="badge {{ $log->isSuccess() ? 'bg-success' : 'bg-danger' }}">
                            {{ $log->isSuccess() ? '成功' : '失败' }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<script>
function copyCallbackUrl() {
    const input = document.getElementById('callbackUrl');
    input.select();
    document.execCommand('copy');
    alert('回调 URL 已复制到剪贴板');
}
</script>
@endsection
