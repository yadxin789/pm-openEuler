@extends('layouts.app')

@section('title', 'AI 设置')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="fa fa-cog me-2 text-primary"></i>AI 设置</h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('ai.chat') }}" class="btn btn-sm btn-outline-primary">AI 助手</a>
                    <a href="{{ route('ai.welcome') }}" class="btn btn-sm btn-outline-secondary">开通引导</a>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    @if($setting->isAvailable())
                    <div class="alert alert-success">
                        ✅ AI 已启用：{{ $providerInfo['name'] ?? $setting->provider }}（{{ $setting->model }}）
                    </div>
                    @else
                    <div class="alert alert-warning">⚠️ AI 尚未启用，配置 API Key 后勾选启用即可。</div>
                    @endif

                    <form method="POST" action="{{ route('ai.settings.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label">提供商</label>
                            <select name="provider" id="provider" class="form-select">
                                @foreach($providers as $key => $p)
                                <option value="{{ $key }}" data-url="{{ $p['base_url'] }}" {{ $setting->provider === $key ? 'selected' : '' }}>{{ $p['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">API Key</label>
                            <input type="password" name="api_key" class="form-control font-monospace" value="{{ old('api_key', $setting->api_key && !str_starts_with($setting->api_key, 'sk-xxxx') ? $setting->api_key : '') }}" placeholder="sk-xxxx…（留空则保持原值）">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Base URL</label>
                            <input type="text" name="base_url" id="base_url" class="form-control font-monospace" value="{{ old('base_url', $setting->base_url) }}">
                            <div class="form-text">切换提供商时自动填充默认地址</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">模型</label>
                            <select name="model" id="model" class="form-select">
                                @foreach(($providerInfo['models'] ?? []) as $m => $label)
                                <option value="{{ $m }}" {{ $setting->model === $m ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">温度（Temperature）</label>
                                <input type="number" step="0.1" min="0" max="2" name="temperature" class="form-control" value="{{ $setting->temperature }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">最大 Token</label>
                                <input type="number" min="100" max="8000" name="max_tokens" class="form-control" value="{{ $setting->max_tokens }}">
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1" {{ $setting->enabled ? 'checked' : '' }}>
                            <label class="form-check-label" for="enabled">启用 AI 功能</label>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-primary">保存设置</button>
                            <a href="{{ route('ai.settings.test') }}" class="btn btn-outline-secondary">测试连接</a>
                        </div>
                    </form>

                    <hr class="my-4">
                    <h6 class="fw-bold mb-2">AI 数据 API</h6>
                    <div class="small text-muted mb-2">
                        供 AI / 外部 Agent 调取 PM 数据（项目、任务、用户、知识库等）的 REST 接口：
                        <code>/api/ai/meta</code>（能力清单）、<code>/api/ai/overview</code>、<code>/api/ai/projects</code>、<code>/api/ai/tasks</code> 等。
                    </div>
                    <div class="small text-muted">
                        调用认证：请求头 <code>X-AI-Key</code> 或参数 <code>api_key</code>，密钥为 <code>.env</code> 中的 <code>AI_API_KEY</code>。
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const provider = document.getElementById('provider');
    const baseUrl = document.getElementById('base_url');
    const model = document.getElementById('model');
    const providers = @json($providers);

    provider.addEventListener('change', function () {
        const url = this.options[this.selectedIndex].dataset.url;
        if (url) baseUrl.value = url;
        // 重置模型下拉
        const info = providers[this.value] || {};
        model.innerHTML = '';
        (info.models || []).forEach ? null : null;
        for (const [k, label] of Object.entries(info.models || {})) {
            const opt = document.createElement('option');
            opt.value = k; opt.textContent = label;
            model.appendChild(opt);
        }
        if (Object.keys(info.models || {}).length) model.value = Object.keys(info.models)[0];
    });
});
</script>
@endsection
