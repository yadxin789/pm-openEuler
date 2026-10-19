@extends('layouts.app')

@section('title', 'AI 开通引导')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            {{-- 头部 --}}
            <div class="text-center mb-4">
                <h1 class="fw-bold"><i class="fa fa-robot me-2 text-primary"></i>欢迎使用 AI 智能助手</h1>
                <p class="text-muted">只需 3 步，即可启用「项目/任务智能问答、PM 数据洞察、自然语言生成文档」等 AI 能力</p>
            </div>

            {{-- 步骤指示器 --}}
            <div class="d-flex justify-content-center mb-4">
                <div class="d-flex align-items-center gap-3 text-sm">
                    <span class="d-flex align-items-center gap-2"><span class="badge rounded-pill bg-primary" style="width:26px;height:26px;line-height:20px;">1</span> 选择提供商</span>
                    <span class="text-muted">→</span>
                    <span class="d-flex align-items-center gap-2"><span class="badge rounded-pill bg-secondary text-white" style="width:26px;height:26px;line-height:20px;">2</span> 获取 API Key</span>
                    <span class="text-muted">→</span>
                    <span class="d-flex align-items-center gap-2"><span class="badge rounded-pill bg-secondary text-white" style="width:26px;height:26px;line-height:20px;">3</span> 粘贴并测试</span>
                </div>
            </div>

            {{-- 第一步：选择提供商 --}}
            <h2 class="h5 fw-bold mb-3">第 1 步：选择 AI 提供商</h2>
            <div class="row g-3 mb-4">
                @foreach($providers as $key => $p)
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100 {{ $current['provider'] === $key ? 'border-primary bg-light' : '' }}">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <h5 class="mb-0 fw-bold">{{ $p['name'] }}</h5>
                            @if($key === 'deepseek')
                            <span class="badge bg-warning text-dark">⭐ 推荐</span>
                            @endif
                        </div>
                        <div class="small text-muted mb-1">{{ $p['price'] }}</div>
                        <div class="small text-success mb-2">🎁 {{ $p['free_quota'] }}</div>
                        <div class="small text-muted mb-3">💡 {{ $p['best_for'] }}</div>
                        <a href="{{ $p['docs'] }}" target="_blank" class="small text-primary">
                            前往 {{ explode('（', $p['name'])[0] }} 注册并获取 Key →
                        </a>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- 第二三步：粘贴 Key 并测试 --}}
            <h2 class="h5 fw-bold mb-3">第 2-3 步：粘贴 API Key 并测试</h2>
            <div class="card">
                <div class="card-body">
                    @if($current['enabled'] && !empty($current['api_key']) && !str_starts_with($current['api_key'], 'sk-xxxx'))
                    <div class="alert alert-success">
                        ✅ AI 功能已启用！当前使用：{{ $providers[$current['provider']]['name'] ?? $current['provider'] }}（{{ $current['model'] }}）
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('ai.chat') }}" class="btn btn-primary">开始使用 AI 助手 →</a>
                        <a href="{{ route('ai.settings') }}" class="btn btn-outline-secondary">修改设置</a>
                    </div>
                    @else
                    <form method="POST" action="{{ route('ai.settings.update') }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label class="form-label">选择提供商</label>
                            <select name="provider" class="form-select">
                                @foreach($providers as $key => $p)
                                <option value="{{ $key }}" {{ $current['provider'] === $key ? 'selected' : '' }}>{{ $p['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">API Key</label>
                            <input type="password" name="api_key" required class="form-control font-monospace" placeholder="sk-xxxxxxxxxxxxxxxx">
                            <div class="form-text">在上方第 1 步点击对应链接即可获取</div>
                        </div>
                        <input type="hidden" name="enabled" value="1">
                        <button class="btn btn-primary">保存并启用 AI</button>
                    </form>
                    @endif
                </div>
            </div>

            {{-- 底部提示 --}}
            <div class="mt-4 bg-light rounded p-3 small text-muted">
                <h6 class="fw-bold mb-2">💡 使用提示</h6>
                <ul class="mb-0">
                    <li>所有 AI 调用直接发送到对应厂商，系统不存储你的对话内容（文档生成结果仅保存在本次会话）</li>
                    <li>智能问答、文档生成等功能会消耗 API 额度</li>
                    <li>可随时在 <a href="{{ route('ai.settings') }}" class="text-primary">AI 设置</a> 中更换提供商或调整模型</li>
                    <li>新用户建议先用 DeepSeek，性价比最高且送 ¥10 额度</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
