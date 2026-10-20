@extends('layouts.app')

@section('title', 'AI 智能助手')

@section('content')
<style>
    .ai-chat-page { display:flex; height: calc(100vh - 140px); gap: 16px; }
    .ai-side { width: 260px; flex: 0 0 260px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; display:flex; flex-direction:column; }
    .ai-side-header { padding:12px; border-bottom:1px solid #eef0f2; }
    .ai-side-list { flex:1; overflow-y:auto; padding:8px; }
    .conv-item { padding:10px; border-radius:8px; cursor:pointer; border:1px solid transparent; margin-bottom:4px; }
    .conv-item:hover { background:#f5f7fa; }
    .conv-item.active { background:#eef2ff; border-color:#c7d2fe; }
    .conv-title { font-size:13px; font-weight:600; color:#1f2937; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .conv-meta { font-size:11px; color:#9ca3af; display:flex; justify-content:space-between; gap:6px; }
    .conv-del { color:#9ca3af; }
    .conv-del:hover { color:#dc3545; }
    .ai-main { flex:1; display:flex; flex-direction:column; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    .ai-main-head { padding:10px 14px; border-bottom:1px solid #eef0f2; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .ai-main-body { flex:1; overflow-y:auto; padding:16px; background:#f8f9fa; }
    .ai-msg { display:flex; margin-bottom:14px; gap:8px; }
    .ai-msg.user { justify-content:flex-end; }
    .ai-avatar { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:13px; flex:0 0 30px; }
    .ai-avatar.assistant { background:linear-gradient(135deg,#667eea,#764ba2); }
    .ai-avatar.user { background:#667eea; }
    .ai-bubble { max-width:76%; border-radius:10px; padding:10px 12px; font-size:14px; line-height:1.7; word-break:break-word; }
    .ai-bubble.user { background:#667eea; color:#fff; white-space:pre-wrap; }
    .ai-bubble.assistant { background:#fff; border:1px solid #e5e7eb; }
    .ai-bubble pre { background:#f6f8fa; padding:8px 10px; border-radius:6px; overflow-x:auto; margin:6px 0; }
    .ai-bubble code { background:#f1f5f9; padding:1px 4px; border-radius:4px; font-size:13px; }
    .ai-bubble pre code { background:transparent; padding:0; }
    .ai-bubble ul, .ai-bubble ol { margin:6px 0 6px 20px; }
    .ai-bubble table { border-collapse:collapse; margin:6px 0; }
    .ai-bubble th, .ai-bubble td { border:1px solid #e5e7eb; padding:4px 8px; }
    .ai-attach { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:6px; }
    .ai-attach-item { display:flex; align-items:center; gap:6px; max-width:220px; padding:4px 8px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; font-size:12px; color:#374151; }
    .ai-attach-item img { width:44px; height:44px; object-fit:cover; border-radius:4px; }
    .ai-composer { border-top:1px solid #eef0f2; padding:10px 12px; background:#fff; }
    .ai-composer textarea { resize:none; }
    .ai-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
    .chip { display:inline-flex; align-items:center; gap:6px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:14px; padding:3px 10px; font-size:12px; color:#374151; }
    .chip i.remove-chip { cursor:pointer; color:#9ca3af; }
    .chip i.remove-chip:hover { color:#dc3545; }
    .ai-composer-tools { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .ai-empty { text-align:center; color:#9ca3af; padding:60px 10px; }
    .ai-empty .suggest { display:inline-block; margin:6px 4px 0; padding:6px 10px; border:1px solid #e5e7eb; border-radius:16px; background:#fff; cursor:pointer; font-size:12px; color:#4b5563; }
    .ai-drop-hint { position:absolute; inset:0; border:2px dashed #667eea; border-radius:10px; background:rgba(102,126,234,.06); display:flex; align-items:center; justify-content:center; color:#667eea; font-weight:600; pointer-events:none; z-index:5; }
    .ai-drop-wrapper { position:relative; }
    .msg-time { font-size:11px; color:#9ca3af; margin-top:2px; }
    .copy-btn { font-size:11px; color:#9ca3af; cursor:pointer; }
    .copy-btn:hover { color:#667eea; }
</style>

<div class="container-fluid">
    <div class="ai-chat-page">
        {{-- 左侧：会话列表 --}}
        <div class="ai-side">
            <div class="ai-side-header">
                <form action="{{ route('ai.conversations.store') }}" method="POST" class="mb-2">
                    @csrf
                    <input type="hidden" name="skill" id="new-conv-skill">
                    <button class="btn btn-primary btn-sm w-100"><i class="fa fa-plus me-1"></i>新建会话</button>
                </form>
                <input type="text" id="conv-search" class="form-control form-control-sm" placeholder="搜索会话标题…">
            </div>
            <div class="ai-side-list" id="conv-list">
                @forelse($conversations as $conv)
                <div class="conv-item {{ $conversation && $conv->id === $conversation->id ? 'active' : '' }}"
                     data-conv-title="{{ mb_strtolower($conv->title) }}">
                    <div class="d-flex align-items-start gap-1">
                        <a href="{{ route('ai.chat', ['c' => $conv->id]) }}" class="flex-fill text-decoration-none" style="min-width:0;">
                            <div class="conv-title">{{ $conv->title }}</div>
                            <div class="conv-meta">
                                <span>{{ $conv->message_count ?? 0 }} 条</span>
                                <span>{{ optional($conv->last_message_at)->format('m-d H:i') }}</span>
                            </div>
                        </a>
                        <div class="dropdown">
                            <span class="conv-del" data-bs-toggle="dropdown" role="button"><i class="fa fa-ellipsis-v"></i></span>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="#" data-toggle-rename="{{ $conv->id }}" data-current="{{ e($conv->title) }}">
                                        <i class="fa fa-pencil me-1"></i>重命名
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="{{ route('ai.conversations.export', $conv->id) }}">
                                        <i class="fa fa-download me-1"></i>导出 Markdown
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" data-delete-conv="{{ $conv->id }}">
                                        <i class="fa fa-trash me-1"></i>删除会话
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                @empty
                <div class="text-muted small text-center mt-4">暂无会话，点击上方「新建会话」开始</div>
                @endforelse
            </div>
        </div>

        {{-- 右侧：聊天区 --}}
        <div class="ai-main ai-drop-wrapper" id="drop-wrapper">
            <div class="ai-drop-hint d-none" id="drop-hint">松开即可添加附件</div>

            <div class="ai-main-head">
                <div class="flex-fill" style="min-width:180px;">
                    <div class="fw-bold" id="current-conv-title">
                        {{ $conversation->title ?? '新会话' }}
                        @if($conversation && $conversation->skill_slug)
                        <span class="badge bg-info ms-1">技能：{{ $conversation->skill_slug }}</span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        {{ $providerInfo['name'] ?? '' }} · {{ $model }}
                        · 限流 {{ $rateLimit['minute'] }}/分钟、{{ $rateLimit['daily'] }}/天
                        @if(!$visionEnabled)
                        <span class="badge bg-light text-muted ms-1" title="管理员可在 AI 设置中开启视觉模型">未开启看图能力</span>
                        @endif
                    </div>
                </div>
                <select id="skill-select" class="form-select form-select-sm" style="width:auto;max-width:220px;">
                    <option value="">默认能力（全部工具）</option>
                    @foreach($skills as $s)
                    <option value="{{ $s->slug }}" {{ optional($conversation)->skill_slug === $s->slug ? 'selected' : '' }}
                            title="{{ e($s->description) }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                @if($conversation)
                <form action="{{ route('ai.conversations.clear', $conversation->id) }}" method="POST"
                      onsubmit="return confirm('确定清空当前会话的全部消息吗？附件也会一并删除。');">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary">清空</button>
                </form>
                <a href="{{ route('ai.conversations.export', $conversation->id) }}" class="btn btn-sm btn-outline-secondary">导出</a>
                @endif
                <a href="{{ route('ai.docs') }}" class="btn btn-sm btn-outline-primary">生成文档</a>
            </div>

            <div class="ai-main-body" id="chat-box">
                @forelse($messages as $msg)
                    @php($isUser = $msg->role === 'user')
                    <div class="ai-msg {{ $isUser ? 'user' : '' }}">
                        @if(!$isUser)
                        <div class="ai-avatar assistant"><i class="fa fa-robot"></i></div>
                        @endif
                        <div style="max-width:76%;">
                            @if($msg->attachments->isNotEmpty())
                            <div class="ai-attach {{ $isUser ? 'justify-content-end' : '' }}">
                                @foreach($msg->attachments as $att)
                                    @if($att->isImage())
                                    <a href="{{ route('ai.attachments.preview', $att->id) }}" target="_blank" class="ai-attach-item text-decoration-none">
                                        <img src="{{ route('ai.attachments.preview', $att->id) }}" alt="{{ $att->original_name }}">
                                        <span class="text-truncate" style="max-width:110px;" title="{{ $att->original_name }}">{{ $att->original_name }}</span>
                                    </a>
                                    @else
                                    <a href="{{ route('ai.attachments.download', $att->id) }}" class="ai-attach-item text-decoration-none">
                                        <i class="fa {{ $att->icon() }}"></i>
                                        <span class="text-truncate" style="max-width:140px;" title="{{ $att->original_name }}">{{ $att->original_name }}</span>
                                        <span class="text-muted">{{ $att->readableSize() }}</span>
                                    </a>
                                    @endif
                                @endforeach
                            </div>
                            @endif
                            <div class="ai-bubble {{ $isUser ? 'user' : 'assistant' }}" data-raw="{{ e($msg->content) }}">{{ $msg->content }}</div>
                            <div class="msg-time {{ $isUser ? 'text-end' : '' }}">
                                {{ optional($msg->created_at)->format('H:i') }}
                                @if(!$isUser)
                                <span class="copy-btn ms-2" title="复制"><i class="fa fa-copy"></i> 复制</span>
                                @endif
                            </div>
                        </div>
                        @if($isUser)
                        <div class="ai-avatar user">{{ mb_substr(auth()->user()->name ?? '我', 0, 1) }}</div>
                        @endif
                    </div>
                @empty
                    <div class="ai-empty">
                        <i class="fa fa-comments-o fa-3x mb-3 d-block"></i>
                        <div>我是 PM 系统智能助手，可以查询真实项目 / 任务 / 知识库数据。</div>
                        <div class="mt-3">
                            <span class="suggest" data-suggest="当前有哪些进行中的项目？">当前有哪些进行中的项目？</span>
                            <span class="suggest" data-suggest="帮我列出本月的逾期任务">帮我列出本月的逾期任务</span>
                            <span class="suggest" data-suggest="研发部本周有哪些任务需要完成？">研发部本周有哪些任务？</span>
                            <span class="suggest" data-suggest="知识库里有哪些规章制度？">知识库里有哪些制度？</span>
                        </div>
                        <div class="mt-3 small">支持上传图片与文本/表格文件作为上下文，也可选择上方「技能」限定助手专长。</div>
                    </div>
                @endforelse
            </div>

            <div class="ai-composer">
                <div class="ai-chips" id="attachment-chips"></div>
                <textarea id="message-input" class="form-control mb-2" rows="2" placeholder="输入你的问题，Enter 发送，Shift+Enter 换行；可直接拖拽或粘贴图片"></textarea>
                <div class="ai-composer-tools">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-image">
                        <i class="fa fa-image me-1"></i>添加图片
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-auto" id="btn-file">
                        <i class="fa fa-paperclip me-1"></i>添加文件
                    </button>
                    <span class="small text-muted me-2">已选择 <b id="attach-count">0</b> 个附件</span>
                    <button class="btn btn-primary btn-sm" id="send-btn">发送</button>
                </div>
                <input type="file" id="image-input" accept="image/*" multiple class="d-none">
                <input type="file" id="file-input" multiple class="d-none">
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const box = document.getElementById('chat-box');
    const input = document.getElementById('message-input');
    const sendBtn = document.getElementById('send-btn');
    const imageInput = document.getElementById('image-input');
    const fileInput = document.getElementById('file-input');
    const chipsBox = document.getElementById('attachment-chips');
    const attachCount = document.getElementById('attach-count');
    const skillSelect = document.getElementById('skill-select');
    const dropHint = document.getElementById('drop-hint');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const conversationsEndpoint = @json(route('ai.conversations.store'));
    const askEndpoint = @json(route('ai.ask'));
    const currentConversationId = {{ optional($conversation)->id ?? 'null' }};
    let conversationId = currentConversationId;

    let pendingImages = [];   // File 对象
    let pendingFiles = [];    // File 对象
    let sending = false;

    function scrollToBottom() { box.scrollTop = box.scrollHeight; }
    scrollToBottom();

    // ---------- 轻量 Markdown 渲染 ----------
    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function renderMarkdown(text) {
        let html = escapeHtml(text);
        html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function (m, lang, code) {
            return '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>';
        });
        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        html = html.replace(/^\s*######\s+(.*)$/gm, '<h6>$1</h6>');
        html = html.replace(/^\s*#####\s+(.*)$/gm, '<h5>$1</h5>');
        html = html.replace(/^\s*####\s+(.*)$/gm, '<h4>$1</h4>');
        html = html.replace(/^\s*###\s+(.*)$/gm, '<h3>$1</h3>');
        html = html.replace(/^\s*##\s+(.*)$/gm, '<h2>$1</h2>');
        html = html.replace(/^\s*#\s+(.*)$/gm, '<h1>$1</h1>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,!?]|$)/g, '$1<em>$2</em>');
        html = html.replace(/^\s*[-*+]\s+(.*)$/gm, '<li>$1</li>');
        html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>');
        html = html.replace(/^\s*(\d+)\.\s+(.*)$/gm, '<li>$2</li>');
        html = html.replace(/^\s*>\s+(.*)$/gm, '<blockquote>$1</blockquote>');
        html = html.replace(/\n/g, '<br>');
        html = html.replace(/<\/ul>\s*<br>/g, '</ul>');
        return html;
    }

    // ---------- DOM 构建 ----------
    function makeAvatar(isUser) {
        const el = document.createElement('div');
        el.className = 'ai-avatar ' + (isUser ? 'user' : 'assistant');
        el.innerHTML = isUser ? @json(mb_substr(auth()->user()->name ?? '我', 0, 1)) : '<i class="fa fa-robot"></i>';
        return el;
    }

    function appendMessage(role, contentHtml) {
        const isUser = role === 'user';
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg ' + (isUser ? 'user' : '');
        if (!isUser) wrap.appendChild(makeAvatar(false));

        const inner = document.createElement('div');
        inner.style.maxWidth = '76%';

        const bubble = document.createElement('div');
        bubble.className = 'ai-bubble ' + (isUser ? 'user' : 'assistant');
        if (isUser) {
            bubble.textContent = contentHtml;
        } else {
            bubble.innerHTML = contentHtml;
        }
        inner.appendChild(bubble);

        const meta = document.createElement('div');
        meta.className = 'msg-time ' + (isUser ? 'text-end' : '');
        const now = new Date();
        meta.innerHTML = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        if (!isUser) {
            meta.innerHTML += ' <span class="copy-btn ms-2" title="复制"><i class="fa fa-copy"></i> 复制</span>';
            meta.querySelector('.copy-btn').addEventListener('click', function () {
                navigator.clipboard.writeText(bubble.innerText).then(function () {
                    const old = meta.querySelector('.copy-btn').innerHTML;
                    meta.querySelector('.copy-btn').innerHTML = '<i class="fa fa-check"></i> 已复制';
                    setTimeout(function () { meta.querySelector('.copy-btn').innerHTML = old; }, 1500);
                });
            });
        }
        inner.appendChild(meta);

        const attachBox = document.createElement('div');
        attachBox.className = 'ai-attach ' + (isUser ? 'justify-content-end' : '');
        inner.insertBefore(attachBox, bubble);

        wrap.appendChild(inner);
        if (isUser) wrap.appendChild(makeAvatar(true));

        const empty = box.querySelector('.ai-empty');
        if (empty) empty.remove();
        box.appendChild(wrap);
        scrollToBottom();
        return { bubble: bubble, attachBox: attachBox };
    }

    function addAttachmentsToBubble(attachBox, attachments) {
        (attachments || []).forEach(function (a) {
            const link = document.createElement('a');
            link.className = 'ai-attach-item text-decoration-none';
            link.target = '_blank';
            if (a.type === 'image' && a.preview_url) {
                link.href = a.preview_url;
                const img = document.createElement('img');
                img.src = a.preview_url;
                img.alt = a.name;
                link.appendChild(img);
            } else {
                link.href = a.download_url || a.preview_url || '#';
                const icon = document.createElement('i');
                icon.className = 'fa fa-file-text-o';
                link.appendChild(icon);
            }
            const name = document.createElement('span');
            name.className = 'text-truncate';
            name.style.maxWidth = '120px';
            name.textContent = a.name;
            name.title = a.name;
            link.appendChild(name);
            const size = document.createElement('span');
            size.className = 'text-muted';
            size.textContent = a.readable_size || '';
            link.appendChild(size);
            attachBox.appendChild(link);
        });
        scrollToBottom();
    }

    // 侧栏追加会话（本次提问自动创建的会话）
    function addConversationToSidebar(id, title) {
        const list = document.getElementById('conv-list');
        if (!list) return;
        const emptyTip = list.querySelector('.text-muted');
        if (emptyTip) emptyTip.remove();

        list.querySelectorAll('.conv-item.active').forEach(function (el) { el.classList.remove('active'); });

        const item = document.createElement('div');
        item.className = 'conv-item active';
        item.dataset.convTitle = (title || '').toLowerCase();
        item.innerHTML =
            '<div class="d-flex align-items-start gap-1">' +
            '<a href="' + @json(route('ai.chat')) + '?c=' + id + '" class="flex-fill text-decoration-none" style="min-width:0;">' +
            '<div class="conv-title"></div>' +
            '<div class="conv-meta"><span>2 条</span><span>刚刚</span></div>' +
            '</a></div>';
        item.querySelector('.conv-title').textContent = title;
        list.insertBefore(item, list.firstChild);
    }

    // ---------- 待上传附件 chips ----------
    function renderChips() {
        chipsBox.innerHTML = '';
        pendingImages.forEach(function (f, idx) {
            chipsBox.appendChild(makeChip('fa-image', f.name, function () {
                pendingImages.splice(idx, 1); renderChips();
            }));
        });
        pendingFiles.forEach(function (f, idx) {
            chipsBox.appendChild(makeChip('fa-file-text-o', f.name, function () {
                pendingFiles.splice(idx, 1); renderChips();
            }));
        });
        attachCount.textContent = String(pendingImages.length + pendingFiles.length);
    }

    function makeChip(icon, name, onRemove) {
        const chip = document.createElement('span');
        chip.className = 'chip';
        chip.innerHTML = '<i class="fa ' + icon + '"></i>' + escapeHtml(name);
        const rm = document.createElement('i');
        rm.className = 'fa fa-times remove-chip';
        rm.addEventListener('click', onRemove);
        chip.appendChild(rm);
        return chip;
    }

    function addFiles(files, asImage) {
        let added = 0;
        Array.prototype.forEach.call(files, function (f) {
            const isImage = f.type && f.type.indexOf('image/') === 0;
            if (asImage && !isImage) return;
            if (f.size > 20 * 1024 * 1024) {
                showGlobalModal('warning', '文件过大', '「' + f.name + '」超过 20MB，已忽略。');
                return;
            }
            if (isImage && asImage) { pendingImages.push(f); added++; }
            else { pendingFiles.push(f); added++; }
        });
        renderChips();
        return added;
    }

    // ---------- 发送 ----------
    async function send() {
        const text = input.value.trim();
        if ((!text && pendingImages.length === 0 && pendingFiles.length === 0) || sending) return;

        sending = true;
        sendBtn.disabled = true;
        sendBtn.textContent = '发送中…';
        input.value = '';

        const userBlock = appendMessage('user', text || '（仅发送附件）');
        const images = pendingImages.slice();
        const files = pendingFiles.slice();
        pendingImages = []; pendingFiles = []; renderChips();

        // 本地先预览图片
        images.forEach(function (f) {
            const link = document.createElement('a');
            link.className = 'ai-attach-item text-decoration-none';
            link.target = '_blank';
            const url = URL.createObjectURL(f);
            link.href = url;
            const img = document.createElement('img');
            img.src = url;
            link.appendChild(img);
            const nm = document.createElement('span');
            nm.className = 'text-truncate';
            nm.style.maxWidth = '110px';
            nm.textContent = f.name;
            link.appendChild(nm);
            userBlock.attachBox.appendChild(link);
        });
        files.forEach(function (f) {
            const link = document.createElement('a');
            link.className = 'ai-attach-item text-decoration-none';
            link.innerHTML = '<i class="fa fa-file-text-o"></i>';
            const nm = document.createElement('span');
            nm.className = 'text-truncate';
            nm.style.maxWidth = '140px';
            nm.textContent = f.name;
            link.appendChild(nm);
            userBlock.attachBox.appendChild(link);
        });

        const thinkingEl = appendMessage('assistant', '<span class="text-muted"><i class="fa fa-circle-o-notch fa-spin me-1"></i>正在思考…</span>');

        const fd = new FormData();
        fd.append('message', text);
        if (conversationId) fd.append('conversation_id', conversationId);
        if (skillSelect.value) fd.append('skill', skillSelect.value);
        images.forEach(function (f) { fd.append('images[]', f); });
        files.forEach(function (f) { fd.append('files[]', f); });

        const controller = new AbortController();
        const timer = setTimeout(function () { controller.abort(); }, 150000);

        try {
            const res = await fetch(askEndpoint, {
                method: 'POST',
                body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal
            });
            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) {
                thinkingEl.bubble.innerHTML = '<span class="text-danger">' + (data.reply || data.message || ('请求失败（' + res.status + '）')) + '</span>';
            } else {
                thinkingEl.bubble.innerHTML = renderMarkdown(data.reply || '抱歉，暂无回复。');
                if (Array.isArray(data.attachments) && data.attachments.length) {
                    addAttachmentsToBubble(userBlock.attachBox, data.attachments);
                }
                if (data.conversation_id && data.conversation_id !== conversationId) {
                    conversationId = data.conversation_id;
                    // 本次请求新建了会话：标题同步 + 侧栏静默追加，避免刷新后丢上下文
                    const titleEl = document.getElementById('current-conv-title');
                    if (titleEl && data.conversation_title) {
                        titleEl.textContent = data.conversation_title;
                    }
                    if (!currentConversationId) {
                        addConversationToSidebar(data.conversation_id, data.conversation_title || '新会话');
                    }
                }
            }
        } catch (e) {
            thinkingEl.bubble.innerHTML = '<span class="text-danger">' + ((e && e.name === 'AbortError')
                ? '请求超时或网络中断，请检查网络后重试。'
                : '网络错误，请重试。') + '</span>';
        } finally {
            clearTimeout(timer);
            sending = false;
            sendBtn.disabled = false;
            sendBtn.textContent = '发送';
            scrollToBottom();
        }
    }

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    input.addEventListener('paste', function (e) {
        const items = (e.clipboardData && e.clipboardData.items) || [];
        let has = false;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type && items[i].type.indexOf('image/') === 0) {
                const f = items[i].getAsFile();
                if (f) { pendingImages.push(f); has = true; }
            }
        }
        if (has) { e.preventDefault(); renderChips(); }
    });

    document.getElementById('btn-image').addEventListener('click', function () { imageInput.click(); });
    document.getElementById('btn-file').addEventListener('click', function () { fileInput.click(); });
    imageInput.addEventListener('change', function () { addFiles(this.files, true); this.value = ''; });
    fileInput.addEventListener('change', function () { addFiles(this.files, false); this.value = ''; });

    // 拖拽上传
    const wrapper = document.getElementById('drop-wrapper');
    ['dragenter', 'dragover'].forEach(function (evt) {
        wrapper.addEventListener(evt, function (e) { e.preventDefault(); dropHint.classList.remove('d-none'); });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        wrapper.addEventListener(evt, function (e) { e.preventDefault(); dropHint.classList.add('d-none'); });
    });
    wrapper.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) { addFiles(e.dataTransfer.files, false); }
    });

    // 侧栏：搜索 / 删除 / 重命名
    const search = document.getElementById('conv-search');
    search.addEventListener('input', function () {
        const kw = this.value.trim().toLowerCase();
        document.querySelectorAll('#conv-list .conv-item').forEach(function (item) {
            const hit = !kw || (item.dataset.convTitle || '').indexOf(kw) !== -1;
            item.style.display = hit ? '' : 'none';
        });
    });

    const newConvSkill = document.getElementById('new-conv-skill');
    newConvSkill.closest('form').addEventListener('submit', function () {
        newConvSkill.value = skillSelect.value;
    });

    document.querySelectorAll('[data-delete-conv]').forEach(function (btn) {
        btn.addEventListener('click', async function (e) {
            e.preventDefault();
            if (!confirm('确定删除该会话及其全部附件吗？此操作不可恢复。')) return;
            const id = this.dataset.deleteConv;
            const res = await fetch(@json(url('/ai/conversations')) + '/' + id, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (res.ok) {
                const item = this.closest('.conv-item');
                if (id === String(conversationId)) {
                    window.location.href = @json(route('ai.chat'));
                } else {
                    item.remove();
                }
            } else {
                showGlobalModal('danger', '删除失败', '会话删除失败，请刷新后重试。');
            }
        });
    });

    document.querySelectorAll('[data-toggle-rename]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const id = this.dataset.toggleRename;
            const current = this.dataset.current;
            const title = prompt('输入新的会话名称：', current);
            if (!title) return;
            const fd = new FormData();
            fd.append('_method', 'PATCH');
            fd.append('title', title);
            fetch(@json(url('/ai/conversations')) + '/' + id, {
                method: 'POST',
                body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function () { window.location.reload(); });
        });
    });

    document.querySelectorAll('[data-suggest]').forEach(function (el) {
        el.addEventListener('click', function () {
            input.value = this.dataset.suggest;
            input.focus();
        });
    });

    // 历史消息（服务端渲染）：Markdown 渲染 + 复制
    document.querySelectorAll('#chat-box .ai-bubble.assistant').forEach(function (b) {
        const raw = b.dataset.raw;
        if (raw) b.innerHTML = renderMarkdown(raw);
    });
    document.querySelectorAll('#chat-box .copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const bubble = this.closest('div').parentElement.querySelector('.ai-bubble');
            navigator.clipboard.writeText(bubble.innerText).then(function () {
                const old = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-check"></i> 已复制';
                setTimeout(function () { btn.innerHTML = old; }, 1500);
            });
        });
    });
    scrollToBottom();
});
</script>
@endsection
