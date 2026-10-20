@extends('layouts.app')

@section('title', 'AI 智能助手')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="fa fa-robot me-2 text-primary"></i>AI 智能助手
                    <span class="badge bg-secondary align-middle">{{ $providerInfo['name'] ?? '' }}</span>
                </h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('ai.docs') }}" class="btn btn-sm btn-outline-primary">生成文档</a>
                    <form action="{{ route('ai.clear') }}" method="POST">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">清空对话</button>
                    </form>
                </div>
            </div>

            <div class="alert alert-info py-2 mb-3 small d-flex align-items-center gap-2">
                <i class="fa fa-info-circle"></i>
                <span>当前 AI 用量限制：每分钟 <strong>{{ $rateLimit['minute'] }}</strong> 次、每日 <strong>{{ $rateLimit['daily'] }}</strong> 次（由管理员在「AI 设置」中调整）。</span>
            </div>

            <div class="card">
                <div class="card-body" id="chat-box" style="height:60vh;overflow-y:auto;display:flex;flex-direction:column;gap:12px;background:#f8f9fa;">
                    {{-- 历史消息 --}}
                    @foreach($history as $msg)
                    <div class="d-flex {{ $msg['role'] === 'user' ? 'justify-content-end' : 'justify-content-start' }}">
                        <div class="{{ $msg['role'] === 'user' ? 'bg-primary text-white' : 'bg-white border' }} rounded p-2 px-3" style="max-width:80%;white-space:pre-wrap;">
                            {{ $msg['content'] }}
                        </div>
                    </div>
                    @endforeach
                    @if(empty($history))
                    <div class="text-center text-muted my-auto">
                        <i class="fa fa-comments-o fa-3x mb-2 d-block"></i>
                        你好，我是 PM 系统智能助手。可以问我：<br>
                        「当前有哪些进行中的项目？」「帮我查逾期任务」「本月的知识库文档有哪些」等
                    </div>
                    @endif
                </div>
                <div class="card-footer d-flex gap-2">
                    <textarea id="message-input" class="form-control" rows="2" placeholder="输入你的问题，回车发送…"></textarea>
                    <button id="send-btn" class="btn btn-primary" style="height:auto;">发送</button>
                </div>
            </div>

            <div class="small text-muted mt-2">
                💡 助手可调用工具查询项目、任务、用户、部门、知识库、文件、待办等 PM 真实数据，回答基于系统数据，不编造。
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

    function scrollToBottom() { box.scrollTop = box.scrollHeight; }
    scrollToBottom();

    function appendMessage(role, content) {
        const wrap = document.createElement('div');
        wrap.className = 'd-flex ' + (role === 'user' ? 'justify-content-end' : 'justify-content-start');
        const bubble = document.createElement('div');
        bubble.className = (role === 'user' ? 'bg-primary text-white' : 'bg-white border') + ' rounded p-2 px-3';
        bubble.style.maxWidth = '80%';
        bubble.style.whiteSpace = 'pre-wrap';
        bubble.textContent = content;
        wrap.appendChild(bubble);
        box.appendChild(wrap);
        scrollToBottom();
        return bubble;   // 返回气泡元素，供后续更新回复内容
    }

    let sending = false;

    async function send() {
        const message = input.value.trim();
        if (!message || sending) return;
        sending = true;
        input.value = '';
        sendBtn.disabled = true;
        sendBtn.textContent = '发送中…';
        appendMessage('user', message);

        // appendMessage 返回「正在思考…」气泡，回复返回后原地更新内容
        const thinkingWrap = appendMessage('assistant', '正在思考…');

        try {
            const fd = new FormData();
            fd.append('message', message);
            // 超时保护：服务端 AI 调用最长约 120s，这里 150s 内未返回视为网络中断，
            // 避免页面一直停留在「正在思考…」
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 150000);
            let res;
            try {
                res = await fetch('{{ route("ai.ask") }}', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, signal: controller.signal });
            } finally {
                clearTimeout(timer);
            }
            if (!res.ok) {
                let msg = '请求失败（' + res.status + '）。';
                try { const j = await res.json(); msg = j.message || msg; } catch (e) {}
                thinkingWrap.textContent = msg;
            } else {
                const data = await res.json();
                thinkingWrap.textContent = data.reply || '抱歉，暂无回复。';
            }
        } catch (e) {
            thinkingWrap.textContent = (e && e.name === 'AbortError')
                ? '请求超时或网络中断，请检查网络后重试。'
                : '网络错误，请重试。';
        } finally {
            sending = false;
            sendBtn.disabled = false;
            sendBtn.textContent = '发送';
        }
        scrollToBottom();
    }

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
});
</script>
@endsection
