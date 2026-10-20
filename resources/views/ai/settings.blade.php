@extends('layouts.app')

@section('title', 'AI 设置')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-11">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="fa fa-cog me-2 text-primary"></i>AI 设置
                    <small class="text-muted fs-6 ms-2">管理员专用：多供应商 / 多模型 / API Key / 调用限流</small>
                </h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('ai.welcome') }}" class="btn btn-sm btn-outline-secondary">开通引导</a>
                </div>
            </div>

            @foreach(['success', 'error', 'warning'] as $t)
                @if(session($t))
                <div class="alert alert-{{ $t === 'error' ? 'danger' : ($t === 'success' ? 'success' : 'warning') }} py-2">{{ session($t) }}</div>
                @endif
            @endforeach

            @php
                $usableCount = $models->filter(fn($m) => $m->isUsable())->count();
            @endphp
            @if(!$setting->isAvailable() || $usableCount === 0)
            <div class="alert alert-warning py-2">⚠️ AI 当前不可用：请至少为一家供应商填写 API Key、勾选「启用」，并保证该供应商下有「开放给用户」的模型。</div>
            @endif

            {{-- ============ 概览 ============ --}}
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">当前生效配置</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="small text-muted">状态</div>
                            <div>
                                @if($setting->isAvailable() && $usableCount > 0)
                                <span class="badge bg-success">已开通 · 运行中</span>
                                @else
                                <span class="badge bg-secondary">{{ $setting->enabled ? '已启用但无可用模型' : '未启用' }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">已开通供应商</div>
                            <div class="fw-bold">{{ $rows->filter(fn($p) => $p->isUsable())->count() }} / {{ $rows->count() }} 家</div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">用户可选模型</div>
                            <div class="fw-bold">{{ $usableCount }} 个</div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">默认模型</div>
                            <div class="fw-bold small">
                                {{ $defaultModel ? $defaultModel->fullLabel() : '未设置' }}
                            </div>
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
                            <div class="small">{{ optional($setting->updated_at)->format('Y-m-d H:i:s') ?: '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============ 供应商管理 ============ --}}
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span>① 供应商管理（可同时开通多家，各自独立 Key）</span>
                    <span class="small text-muted fw-normal">用户在聊天页可随时切换到任意已开通供应商下的模型</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:14%">供应商</th>
                                    <th style="width:22%">API Key</th>
                                    <th style="width:22%">Base URL</th>
                                    <th style="width:10%">模型</th>
                                    <th style="width:12%">状态</th>
                                    <th style="width:20%">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                @php($preset = $providers[$row->key] ?? [])
                                <tr>
                                    <td>
                                        <div class="fw-bold">{{ $row->name }}</div>
                                        @if($row->is_default)<span class="badge bg-primary">默认</span>@endif
                                        <div class="small text-muted">{{ $preset['price'] ?? '' }}</div>
                                    </td>
                                    <td>
                                        <div class="font-monospace small">
                                            @if($row->maskedApiKey())
                                            <code>{{ $row->maskedApiKey() }}</code>
                                            @else
                                            <span class="text-danger">未填写</span>
                                            @endif
                                        </div>
                                        @if(!empty($preset['docs']))
                                        <a href="{{ $preset['docs'] }}" target="_blank" class="small">获取 Key →</a>
                                        @endif
                                    </td>
                                    <td class="font-monospace small text-break">{{ $row->base_url ?: '—' }}</td>
                                    <td>
                                        <div>{{ $row->models->count() }} 个（{{ $row->models->where('enabled', true)->count() }} 开放）</div>
                                    </td>
                                    <td>
                                        @if($row->isUsable())
                                        <span class="badge bg-success">已开通</span>
                                        @elseif($row->enabled)
                                        <span class="badge bg-warning text-dark">缺 Key</span>
                                        @else
                                        <span class="badge bg-secondary">已停用</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('ai.providers.save', $row->key) }}" class="row g-1 align-items-center">
                                            @csrf
                                            <div class="col-12">
                                                <div class="input-group input-group-sm">
                                                    <input type="password" name="api_key" class="form-control font-monospace"
                                                           placeholder="{{ $row->maskedApiKey() ? '留空保持不变' : '粘贴 API Key' }}" autocomplete="new-password">
                                                    <button class="btn btn-outline-primary" title="保存 Key">保存 Key</button>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="input-group input-group-sm">
                                                    <input type="text" name="base_url" class="form-control font-monospace"
                                                           value="{{ $row->base_url }}" placeholder="接口地址 Base URL">
                                                    <button class="btn btn-outline-primary" title="保存地址">保存地址</button>
                                                </div>
                                            </div>
                                            <div class="col-12 d-flex gap-1 flex-wrap">
                                                <input type="hidden" name="enabled" value="0">
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="enabled" id="en-{{ $row->id }}" value="1"
                                                           onchange="this.form.submit()" {{ $row->enabled ? 'checked' : '' }}>
                                                    <label class="form-check-label small" for="en-{{ $row->id }}">启用</label>
                                                </div>
                                                @if(!$row->is_default)
                                                <a href="{{ route('ai.providers.default', $row->key) }}" class="btn btn-sm btn-outline-secondary"
                                                   onclick="event.preventDefault(); if(confirm('设为默认供应商：{{ $row->name }}？')) document.getElementById('pdefault-{{ $row->id }}').submit();">设默认</a>
                                                @endif
                                                <a href="{{ route('ai.providers.test', $row->key) }}" class="btn btn-sm btn-outline-info">测试</a>
                                                <a href="{{ route('ai.providers.sync', $row->key) }}" class="btn btn-sm btn-outline-secondary">补模型</a>
                                                @if($row->hasRealApiKey())
                                                <a href="{{ route('ai.providers.remove', $row->key) }}" class="btn btn-sm btn-outline-danger"
                                                   onclick="event.preventDefault(); if(confirm('清除 {{ $row->name }} 的密钥并停用？')) document.getElementById('premove-{{ $row->id }}').submit();">清 Key</a>
                                                @endif
                                            </div>
                                        </form>
                                        @if(!$row->is_default)
                                        <form id="pdefault-{{ $row->id }}" method="POST" action="{{ route('ai.providers.default', $row->key) }}" class="d-none">@csrf</form>
                                        @endif
                                        @if($row->hasRealApiKey())
                                        <form id="premove-{{ $row->id }}" method="POST" action="{{ route('ai.providers.remove', $row->key) }}" class="d-none">@csrf @method('DELETE')</form>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white small text-muted">
                        提示：每家供应商的 Key 只保存在服务器数据库中，前端始终脱敏显示；切换供应商不会影响历史会话记录。
                    </div>
                </div>
            </div>

            {{-- ============ 模型管理 ============ --}}
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">② 模型管理（勾选「开放」后用户在聊天页可见并随时切换）</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:16%">供应商</th>
                                    <th style="width:18%">模型标识</th>
                                    <th style="width:24%">展示名称</th>
                                    <th style="width:10%">看图</th>
                                    <th style="width:12%">状态</th>
                                    <th style="width:20%">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($models as $m)
                                <tr>
                                    <td class="small">{{ $m->provider->name ?? '—' }}</td>
                                    <td class="font-monospace small">{{ $m->model_id }}</td>
                                    <td>
                                        <span class="small">{{ $m->name }}</span>
                                        @if($m->is_default)<span class="badge bg-primary ms-1">默认</span>@endif
                                    </td>
                                    <td>
                                        @if($m->is_vision)<span class="badge bg-info">支持</span>@else<span class="text-muted small">—</span>@endif
                                    </td>
                                    <td>
                                        @if($m->isUsable())
                                        <span class="badge bg-success">用户可选</span>
                                        @elseif($m->enabled)
                                        <span class="badge bg-warning text-dark">供应商未开通</span>
                                        @else
                                        <span class="badge bg-secondary">未开放</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('ai.models.default', $m->id) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary" {{ $m->isUsable() ? '' : 'disabled' }}>设默认</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#medit-{{ $m->id }}">编辑</button>
                                        <form method="POST" action="{{ route('ai.models.destroy', $m->id) }}" class="d-inline"
                                              onsubmit="return confirm('删除模型「{{ $m->name }}」？使用该模型的会话会回退到默认模型。');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">删除</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="medit-{{ $m->id }}">
                                    <td colspan="6" class="bg-light">
                                        <form method="POST" action="{{ route('ai.models.update', $m->id) }}" class="row g-2 align-items-end">
                                            @csrf @method('PUT')
                                            <div class="col-md-3">
                                                <label class="form-label small mb-0">展示名称</label>
                                                <input type="text" name="name" class="form-control form-control-sm" value="{{ $m->name }}">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small mb-0">模型标识（调用用）</label>
                                                <input type="text" name="model_id" class="form-control form-control-sm font-monospace" value="{{ $m->model_id }}">
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-check mb-1">
                                                    <input class="form-check-input" type="checkbox" name="enabled" value="1" id="men-{{ $m->id }}" {{ $m->enabled ? 'checked' : '' }}>
                                                    <label class="form-check-label small" for="men-{{ $m->id }}">开放给用户选择</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_vision" value="1" id="mvs-{{ $m->id }}" {{ $m->is_vision ? 'checked' : '' }}>
                                                    <label class="form-check-label small" for="mvs-{{ $m->id }}">支持看图（图片理解）</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <button class="btn btn-sm btn-primary">保存修改</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">暂无模型，请在上方「补模型」或下方新增自定义模型</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ============ 新增自定义模型 ============ --}}
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">③ 新增自定义模型（厂家新发布的模型可手工添加）</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('ai.models.store') }}" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label small mb-0">所属供应商</label>
                            <select name="provider_id" class="form-select form-select-sm" required>
                                @foreach($rows as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">模型标识（如 deepseek-chat）</label>
                            <input type="text" name="model_id" class="form-control form-control-sm font-monospace" required placeholder="model-name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">展示名称</label>
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="留空则同模型标识">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="enabled" value="1" id="newm-en" checked>
                                <label class="form-check-label small" for="newm-en">开放给用户</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_vision" value="1" id="newm-vs">
                                <label class="form-check-label small" for="newm-vs">支持看图</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-primary">新增模型</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- ============ 全局参数 ============ --}}
            <div class="card">
                <div class="card-header bg-white fw-bold">④ 生成参数与限流（对所有供应商 / 模型生效）</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('ai.settings.update') }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="provider" value="{{ $setting->provider }}">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">温度（Temperature）</label>
                                <input type="number" step="0.1" min="0" max="2" name="temperature" class="form-control" value="{{ $setting->temperature }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">最大 Token</label>
                                <input type="number" min="100" max="8000" name="max_tokens" class="form-control" value="{{ $setting->max_tokens }}">
                            </div>
                            <div class="col-md-4"></div>
                            <div class="col-md-6">
                                <label class="form-label">每用户限流 · 每分钟（次）</label>
                                <input type="number" min="1" max="1000" name="rate_limit_minute" class="form-control" value="{{ $setting->rate_limit_minute ?? 30 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">每用户限流 · 每日（次）</label>
                                <input type="number" min="1" max="100000" name="rate_limit_daily" class="form-control" value="{{ $setting->rate_limit_daily ?? 200 }}">
                                <div class="form-text">限流对所有非管理员用户生效</div>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1" {{ $setting->enabled ? 'checked' : '' }}>
                            <label class="form-check-label" for="enabled">启用 AI 功能（关闭后所有用户不可用，并显示「管理员尚未开通」）</label>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-primary">保存设置</button>
                            <a href="{{ route('ai.settings.test') }}" class="btn btn-outline-secondary">测试默认模型连接</a>
                        </div>
                    </form>

                    <hr class="my-4">
                    <h6 class="fw-bold mb-2">使用说明</h6>
                    <ul class="small text-muted mb-0">
                        <li>可同时开通 DeepSeek、通义千问、豆包、混元等多家供应商，各自填写 Key，互不干扰。</li>
                        <li>用户在聊天页右上角「模型」下拉框中可随时切换本次会话使用的模型；切换后立即生效，无需重建会话。</li>
                        <li>只有勾选「开放给用户选择」且所属供应商已开通的模型才会出现在用户侧列表中。</li>
                        <li>若所选模型不支持看图，上传图片时会自动回退到同供应商下支持视觉的模型（没有则提示无法读图）。</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
