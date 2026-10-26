@extends('layouts.app')

@section('title', 'AI 授权中心')

@section('content')
<h1 class="mb-4"><i class="fa fa-robot me-2"></i>AI 授权中心</h1>

<div class="alert alert-info">
    <b>授权原则：</b>谁对这份文件拥有读 / 写 / 下载的权限，谁就可以授权 AI 读 / 写 / 下载。
    令牌本身不产生任何额外权限，AI 拿到令牌后能做的事，<b>完全等同于你本人</b>。
    <br><b>编辑保护：</b>AI 不能直接改数据。它只能提交「变更提案」，你在下方审阅差异后确认，变更才会真正生效。
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    {{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

@if(session('new_token'))
<div class="alert alert-warning">
    <b>请立即复制并妥善保存令牌，关闭后无法再次查看：</b>
    <div class="input-group mt-2">
        <input type="text" class="form-control font-monospace" id="newToken" value="{{ session('new_token') }}" readonly>
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newToken').value)">复制</button>
    </div>
    <small class="d-block mt-2">调用方式：请求头 <code>X-AI-Key: &lt;系统密钥&gt;</code> + <code>X-AI-Token: &lt;本令牌&gt;</code></small>
</div>
@endif

{{-- ============ 待确认的 AI 变更提案 ============ --}}
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="fa fa-clipboard-check me-2"></i>待确认的 AI 变更（{{ $pending->count() }}）</h5>
    </div>
    <div class="card-body">
        @forelse($pending as $proposal)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-secondary">{{ $proposal->resourceLabel() }}</span>
                    <b class="ms-2">{{ $proposal->actionLabel() }}</b>
                    @if($proposal->resource_id)<small class="text-muted">（ID: {{ $proposal->resource_id }}）</small>@endif
                    <span class="badge bg-warning text-dark ms-2">待确认</span>
                </div>
                <small class="text-muted">
                    提案 #{{ $proposal->id }}，{{ $proposal->expires_at?->diffForHumans() }}过期
                </small>
            </div>
            <div class="card-body">
                @if($proposal->reason)
                <div class="mb-3">
                    <b>AI 说明：</b>
                    <div class="border rounded p-2 bg-light">{{ $proposal->reason }}</div>
                </div>
                @endif

                <b>变更差异：</b>
                <table class="table table-sm table-bordered mt-2">
                    <thead class="table-light">
                        <tr><th style="width:20%">字段</th><th style="width:40%">变更前</th><th style="width:40%">变更后</th></tr>
                    </thead>
                    <tbody>
                        @forelse($proposal->diff() as $field => $change)
                        <tr>
                            <td><code>{{ $field }}</code></td>
                            <td class="text-danger">@if(is_array($change['before']))<pre class="mb-0 small">{{ json_encode($change['before'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>@else{{ $change['before'] ?? '—' }}@endif</td>
                            <td class="text-success">@if(is_array($change['after']))<pre class="mb-0 small">{{ json_encode($change['after'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>@else{{ $change['after'] ?? '—' }}@endif</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-muted">无字段级差异（新建 / 删除类操作）</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if(!empty($proposal->after))
                <details class="mb-3">
                    <summary>查看变更后完整内容</summary>
                    <pre class="border rounded p-2 bg-light small mt-2">{{ json_encode($proposal->after, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
                </details>
                @endif

                <form action="{{ route('ai-delegation.proposals.confirm', $proposal) }}" method="POST" class="d-inline">
                    @csrf
                    <input type="text" name="note" class="form-control form-control-sm d-inline-block mb-2" style="width:auto;" placeholder="批注（可选）">
                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('确认执行该变更？执行后不可撤销。')">
                        <i class="fa fa-check me-1"></i>确认执行
                    </button>
                </form>
                <form action="{{ route('ai-delegation.proposals.reject', $proposal) }}" method="POST" class="d-inline ms-2">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('驳回该提案？业务数据将保持不变。')">
                        <i class="fa fa-times me-1"></i>驳回
                    </button>
                </form>
            </div>
        </div>
        @empty
        <p class="text-muted mb-0">当前没有待确认的 AI 变更提案。</p>
        @endforelse
    </div>
</div>

{{-- ============ 委托令牌 ============ --}}
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa fa-key me-2"></i>我的委托令牌</h5>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>名称</th>
                    <th>令牌</th>
                    <th>授权范围</th>
                    <th>限流</th>
                    <th>有效期至</th>
                    <th>最近使用</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tokens as $token)
                <tr>
                    <td>{{ $token->name }}</td>
                    <td><code>{{ $token->token_prefix }}…</code></td>
                    <td>
                        @foreach((array) $token->scopes as $scope)
                        <span class="badge bg-info text-dark">{{ $scopeOptions[$scope] ?? $scope }}</span>
                        @endforeach
                    </td>
                    <td>{{ $token->rate_limit_per_minute }}/分</td>
                    <td>{{ $token->expires_at?->format('Y-m-d') ?? '长期' }}</td>
                    <td>{{ $token->last_used_at?->diffForHumans() ?? '从未' }}</td>
                    <td>
                        @if($token->revoked_at)
                        <span class="badge bg-secondary">已吊销</span>
                        @elseif($token->expires_at && $token->expires_at->isPast())
                        <span class="badge bg-danger">已过期</span>
                        @else
                        <span class="badge bg-success">生效中</span>
                        @endif
                    </td>
                    <td>
                        @if(!$token->revoked_at)
                        <form action="{{ route('ai-delegation.tokens.revoke', $token) }}" method="POST" onsubmit="return confirm('吊销后使用该令牌的 AI 将立即失去授权，确定？')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">吊销</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-muted">尚未签发任何委托令牌。</td></tr>
                @endforelse
            </tbody>
        </table>

        <hr>
        <h6>签发新令牌</h6>
        <form action="{{ route('ai-delegation.tokens.store') }}" method="POST" class="row g-3">
            @csrf
            <div class="col-md-3">
                <label class="form-label">令牌名称</label>
                <input type="text" name="name" class="form-control" placeholder="例如：文档生成 Agent" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">授权范围</label>
                @foreach($scopeOptions as $scope => $label)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="scopes[]" value="{{ $scope }}" id="scope_{{ $scope }}" {{ $scope === 'read' ? 'checked' : '' }}>
                    <label class="form-check-label" for="scope_{{ $scope }}">{{ $label }}</label>
                </div>
                @endforeach
            </div>
            <div class="col-md-2">
                <label class="form-label">有效天数</label>
                <input type="number" name="days" class="form-control" value="30" min="1" max="730">
            </div>
            <div class="col-md-2">
                <label class="form-label">限流（次/分）</label>
                <input type="number" name="rate_limit_per_minute" class="form-control" value="60" min="1" max="600">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">签发</button>
            </div>
        </form>
    </div>
</div>

{{-- ============ 历史记录 ============ --}}
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa fa-history me-2"></i>变更历史</h5>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <thead>
                <tr><th>#</th><th>对象</th><th>动作</th><th>状态</th><th>批注</th><th>处理时间</th></tr>
            </thead>
            <tbody>
                @forelse($history as $proposal)
                <tr>
                    <td>{{ $proposal->id }}</td>
                    <td>{{ $proposal->resourceLabel() }}@if($proposal->resource_id) #{{ $proposal->resource_id }}@endif</td>
                    <td>{{ $proposal->actionLabel() }}</td>
                    <td>
                        @switch($proposal->status)
                        @case('confirmed')<span class="badge bg-success">已执行</span>@break
                        @case('rejected')<span class="badge bg-secondary">已驳回</span>@break
                        @case('expired')<span class="badge bg-warning text-dark">已过期</span>@break
                        @case('failed')<span class="badge bg-danger">执行失败</span>@break
                        @default<span class="badge bg-light text-dark">{{ $proposal->status }}</span>
                        @endswitch
                    </td>
                    <td>{{ $proposal->resolve_note ?? '—' }}</td>
                    <td>{{ $proposal->resolved_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-muted">暂无记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
