@extends('layouts.app')

@section('title', 'AI 设置')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="fa fa-cog me-2 text-primary"></i>AI 设置
                    <small class="text-muted fs-6 ms-2">管理员专用：开通 / 供应商 / API Key / 调用限流</small>
                </h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('ai.welcome') }}" class="btn btn-sm btn-outline-secondary">开通引导</a>
                </div>
            </div>

            @if(!isset($setting) || !$setting->isAvailable())
            <div class="alert alert-warning py-2">⚠️ AI 当前不可用：请填写供应商 API Key 并勾选「启用 AI 功能」后保存。</div>
            @endif

            {{-- ============ 当前生效配置（只读概览） ============ --}}
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">当前生效配置</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="small text-muted">状态</div>
                            <div>
                                @if($setting->isAvailable())
                                <span class="badge bg-success">已开通 · 运行中</span>
                                @else
                                <span class="badge bg-secondary">
                                    {{ $setting->enabled ? '已启用但未填 Key' : '未启用' }}
                                </span>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">当前供应商</div>
                            <div class="fw-bold">
                                {{ $providers[$setting->provider]['name'] ?? $setting->provider }}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">当前模型</div>
                            <div class="fw-bold font-monospace small">{{ $setting->model }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">调用方式</div>
                            <div class="small">服务端统一调用，全员共享该 Key</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">当前 API Key（脱敏显示）</div>
                            <div class="font-monospace">
                                @if($maskedKey)
                                <code>{{ $maskedKey }}</code>
                                @else
                                <span class="text-danger">尚未填写（请在下方填写）</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">接口地址（Base URL）</div>
                            <div class="font-monospace small">{{ $setting->base_url ?: '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">每用户限流 · 每分钟</div>
                            <div class="fw-bold">{{ $setting->rate_limit_minute ?: 30 }} 次</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">每用户限流 · 每日</div>
                            <div class="fw-bold">{{ $setting->rate_limit_daily ?: 200 }} 次</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">最近更新</div>
                            <div class="small">{{ $setting->updated_at ? $setting->updated_at->format('Y-m-d H:i:s') : '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============ 配置表单 ============ --}}
            <div class="card">
                <div class="card-header bg-white fw-bold">修改配置</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('ai.settings.update') }}">
                        @csrf
                        @method('PUT')

                        <h6 class="fw-bold mb-3">① 供应商与密钥</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">供应商（可重新选择）</label>
                                <select name="provider" id="provider" class="form-select">
                                    @foreach($providers as $key => $p)
                                    <option value="{{ $key }}" data-url="{{ $p['base_url'] }}" {{ $setting->provider === $key ? 'selected' : '' }}>{{ $p['name'] }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">切换后 Base URL 与模型列表会自动更新</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">API Key（手动填写）</label>
                                <div class="input-group">
                                    <input type="password" name="api_key" id="api_key" class="form-control font-monospace"
                                           placeholder="sk-xxxx…（留空则保持原值不变）" autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" id="toggle-key">显示</button>
                                </div>
                                <div class="form-text">
                                    当前已保存：<code class="font-monospace">{{ $maskedKey ?: '未填写' }}</code>；不修改请留空
                                </div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Base URL</label>
                                <input type="text" name="base_url" id="base_url" class="form-control font-monospace" value="{{ old('base_url', $setting->base_url) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">模型</label>
                                <select name="model" id="model" class="form-select">
                                    @foreach(($providerInfo['models'] ?? []) as $m => $label)
                                    <option value="{{ $m }}" {{ $setting->model === $m ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <h6 class="fw-bold mb-3">② 生成参数</h6>
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

                        <h6 class="fw-bold mb-3">③ 调用频率限制（限流）</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">每用户限流 · 每分钟（次）</label>
                                <input type="number" min="1" max="1000" name="rate_limit_minute" class="form-control" value="{{ $setting->rate_limit_minute ?? 30 }}">
                                <div class="form-text">每个用户每分钟最多调用 AI 的次数（防单个用户刷爆共享额度）</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">每用户限流 · 每日（次）</label>
                                <input type="number" min="1" max="100000" name="rate_limit_daily" class="form-control" value="{{ $setting->rate_limit_daily ?? 200 }}">
                                <div class="form-text">每个用户每天最多调用 AI 的总次数</div>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-light border py-2 mb-0 small">
                                    限流对所有非管理员用户生效，管理员可通过此处的阈值控制成本上限。
                                </div>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1" {{ $setting->enabled ? 'checked' : '' }}>
                            <label class="form-check-label" for="enabled">启用 AI 功能（关闭后所有用户不可用，并显示「管理员尚未开通」）</label>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-primary">保存设置</button>
                            <a href="{{ route('ai.settings.test') }}" class="btn btn-outline-secondary">测试连接</a>
                        </div>
                    </form>

                    <hr class="my-4">
                    <h6 class="fw-bold mb-2">使用说明</h6>
                    <ul class="small text-muted mb-4">
                        <li>管理员在本页完成开通后，普通业务账号即可在左侧「AI 智能助手」中使用聊天与文档生成功能；管理员账号本身不使用 AI。</li>
                        <li>AI 数据 API：<code>/api/ai/meta</code>（能力清单）、<code>/api/ai/overview</code>、<code>/api/ai/projects</code>、<code>/api/ai/tasks</code> 等，认证请求头 <code>X-AI-Key</code>（取值为 <code>.env</code> 中的 <code>AI_API_KEY</code>）。</li>
                    </ul>
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
    const currentKeyPreview = @json($maskedKey ?: '');

    provider.addEventListener('change', function () {
        const info = providers[this.value] || {};
        if (info.base_url) baseUrl.value = info.base_url;
        model.innerHTML = '';
        for (const [k, label] of Object.entries(info.models || {})) {
            const opt = document.createElement('option');
            opt.value = k; opt.textContent = label;
            model.appendChild(opt);
        }
        const keys = Object.keys(info.models || {});
        if (keys.length) model.value = keys[0];
    });

    // API Key 显示/隐藏
    const toggle = document.getElementById('toggle-key');
    const keyInput = document.getElementById('api_key');
    toggle.addEventListener('click', function () {
        const show = keyInput.type === 'password';
        keyInput.type = show ? 'text' : 'password';
        toggle.textContent = show ? '隐藏' : '显示';
    });
    // 未修改 Key 时输入框保持为空；此处仅做占位提示，不回显真实密钥
    keyInput.setAttribute('placeholder', currentKeyPreview ? ('已保存 ' + currentKeyPreview + '，留空保持不变') : 'sk-xxxx…');
});
</script>
@endsection
