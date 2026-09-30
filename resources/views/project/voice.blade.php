@extends('layouts.app')

@section('title', '电话会议 - ' . $project->name)

@section('content')
@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'voice'])

<div class="row no-gutters" style="height: calc(100vh - 220px);">
    <div class="col-md-3 col-lg-2 px-0 d-flex flex-column"
         style="border-right: 1px solid #dee2e6; background: #f8f9fa; max-height: calc(100vh - 220px);">
        <div class="p-3 border-bottom bg-white">
            <h6 class="mb-1 text-truncate"><i class="fa fa-phone text-primary me-1"></i>电话会议</h6>
            <small>
                @if($isHost)
                    <span class="badge bg-success"><i class="fa fa-star me-1"></i>你是主持人</span>
                @else
                    <span class="badge bg-secondary">听众</span>
                @endif
            </small>
        </div>
        <div class="px-3 py-2 border-bottom" style="font-size:12px;color:#999;">项目成员</div>
        <div class="flex-grow-1 overflow-y-auto" id="voice-member-list">
            <div class="text-center py-4 text-muted" style="font-size:13px;">加载中...</div>
        </div>
        <div class="p-2 border-top bg-white">
            <button id="btn-apply-presenter" class="btn btn-outline-primary btn-sm w-100" {{ $isHost ? 'disabled' : '' }}>
                <i class="fa fa-hand-paper-o me-1"></i>申请演示
            </button>
        </div>
    </div>

    <div class="col-md-9 col-lg-10 d-flex flex-column" style="max-height: calc(100vh - 220px);">
        <div class="d-flex align-items-center justify-content-between p-2 border-bottom bg-white">
            <div>
                当前演示者：<span id="current-presenter-name" class="fw-bold">未开始</span>
                <span id="apply-banner" class="ms-3 alert alert-warning py-1 px-2 mb-0 d-none"></span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <select id="doc-select" class="form-select form-select-sm" style="width:260px;">
                    <option value="">-- 选择演示文档 --</option>
                    @foreach($documents as $doc)
                        <option value="p:{{ $doc->id }}">[项目] {{ $doc->name }}.{{ $doc->ext }}</option>
                    @endforeach
                </select>
                <input type="file" id="file-upload" accept=".docx,.xlsx,.pptx,.pdf,.doc,.xls,.ppt" style="display:none;">
                <button id="btn-upload" class="btn btn-sm btn-outline-secondary" title="上传本地文件演示">
                    <i class="fa fa-upload"></i> 上传
                </button>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="edit-mode">
                    <label class="form-check-label small" for="edit-mode">编辑模式</label>
                </div>
                <button id="btn-download" class="btn btn-sm btn-outline-secondary" disabled>
                    <i class="fa fa-download"></i> 下载
                </button>
                @if($isHost)
                    <button id="btn-revoke-presenter" class="btn btn-sm btn-outline-danger">
                        <i class="fa fa-undo me-1"></i>收回演示权
                    </button>
                @endif
            </div>
        </div>

        <div class="flex-grow-1 bg-light d-flex align-items-center justify-content-center" id="doc-frame-wrap">
            <div class="text-center text-muted" id="doc-empty-tip">
                <i class="fa fa-file-text-o" style="font-size:48px;"></i>
                <p class="mt-2">主持人为你选择一篇文档后，这里将展示只读协同办公页面</p>
            </div>
            <iframe id="doc-frame" style="display:none; width:100%; height:100%; border:0; background:#fff;"></iframe>
        </div>

        <div class="p-2 border-top bg-white d-flex justify-content-center gap-3 align-items-center">
            <button id="btn-mic" class="btn btn-secondary" disabled>
                <i class="fa fa-microphone"></i> 静音
            </button>
            <button id="btn-call" class="btn btn-primary">
                <i class="fa fa-phone me-1"></i>加入会议
            </button>
            <button id="btn-follow" class="btn btn-outline-secondary" disabled>
                <i class="fa fa-crosshairs me-1"></i>回到演示
            </button>
        </div>
    </div>
</div>

<div id="remote-audios"></div>
@endsection

@section('scripts')
<script type="text/javascript" src="{{ rtrim(config('collab.server_url'), '/') }}/web-apps/apps/api/documents/api.js"></script>
<script>
window.VOICE_STATE = {
    projectId: {{ $project->id }},
    roomId: {{ $room->id }},
    isHost: @json($isHost),
    currentUserId: {{ auth()->id() }},
    currentPresenterId: {{ $room->current_presenter_id ?? 'null' }},
};

const $ = (id) => document.getElementById(id);
let ws = null;
let allMembers = [];
let onlineUids = new Set();
let docEditor = null;
let followingPresenter = true;
let localStream = null;
const peers = {}; // uid -> RTCPeerConnection

// 局域网 ICE 配置：不需要 STUN/TURN
const rtcConfig = { iceServers: [] };

// ---------- 1. 信令 ----------
async function connectSignal() {
    const r = await fetch(`/project/${VOICE_STATE.projectId}/voice/token`).then(r => r.json());
    window._voiceConf = r;
    const url = `${r.signal_ws}?token=${encodeURIComponent(r.token)}`;
    ws = new WebSocket(url);
    ws.onopen = () => console.log('[voice] signal ws open');
    ws.onclose = () => console.log('[voice] signal ws closed');
    ws.onerror = (e) => console.error('[voice] ws error', e);
    ws.onmessage = (e) => handleSignal(JSON.parse(e.data));
}

function sendSignal(obj) {
    if (ws && ws.readyState === 1) ws.send(JSON.stringify(obj));
}

// ---------- 2. 信令消息 ----------
function handleSignal(msg) {
    switch (msg.type) {
        case 'room_state':
            onlineUids = new Set(msg.members.map(m => m.uid));
            renderMembers();
            // Mesh: 新成员加入，旧成员主动发起 offer
            if (localStream) {
                msg.members.forEach(m => {
                    if (m.uid !== VOICE_STATE.currentUserId && !peers[m.uid]) {
                        createPeer(m.uid, true);
                    }
                });
            }
            break;
        case 'presenter_changed':
            VOICE_STATE.currentPresenterId = msg.presenter_uid;
            updatePresenterLabel();
            renderMembers();
            break;
        case 'presenter_apply':
            if (VOICE_STATE.isHost) showApplyBanner(msg.from_uid, msg.from_name);
            break;
        case 'doc_selected':
            loadDoc(msg.doc_id);
            break;
        case 'slide_sync':
            if (followingPresenter) applySlideSync(msg);
            break;
        case 'webrtc_offer':
            handleOffer(msg);
            break;
        case 'webrtc_answer':
            handleAnswer(msg);
            break;
        case 'webrtc_ice':
            handleIce(msg);
            break;
        case 'error':
            console.error('[voice]', msg.reason);
            break;
    }
}

// ---------- 3. Mesh P2P WebRTC ----------
async function createPeer(uid, isInitiator) {
    if (peers[uid]) return peers[uid];
    const pc = new RTCPeerConnection(rtcConfig);
    peers[uid] = pc;

    // 发本地麦克风
    localStream.getTracks().forEach(track => pc.addTrack(track, localStream));

    // 收到远端音频
    pc.ontrack = (e) => {
        let audio = $(`audio-${uid}`);
        if (!audio) {
            audio = document.createElement('audio');
            audio.id = `audio-${uid}`;
            audio.autoplay = true;
            $('remote-audios').appendChild(audio);
        }
        audio.srcObject = e.streams[0];
    };

    pc.onicecandidate = (e) => {
        if (e.candidate) {
            sendSignal({ type: 'webrtc_ice', to_uid: uid, candidate: e.candidate });
        }
    };

    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
            delete peers[uid];
            const a = $(`audio-${uid}`);
            if (a) a.remove();
        }
    };

    if (isInitiator) {
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        sendSignal({ type: 'webrtc_offer', to_uid: uid, sdp: pc.localDescription });
    }
    return pc;
}

async function handleOffer(msg) {
    const uid = msg.from_uid;
    const pc = await createPeer(uid, false);
    await pc.setRemoteDescription(new RTCSessionDescription(msg.sdp));
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    sendSignal({ type: 'webrtc_answer', to_uid: uid, sdp: pc.localDescription });
}

async function handleAnswer(msg) {
    const pc = peers[msg.from_uid];
    if (pc) await pc.setRemoteDescription(new RTCSessionDescription(msg.sdp));
}

async function handleIce(msg) {
    const pc = peers[msg.from_uid];
    if (pc && msg.candidate) await pc.addIceCandidate(new RTCIceCandidate(msg.candidate));
}

// ---------- 4. 成员列表 ----------
async function loadAllMembers() {
    try {
        const r = await fetch(`/project/${VOICE_STATE.projectId}/voice/members`).then(r => r.json());
        allMembers = r.members || [];
        renderMembers();
    } catch (e) { console.error(e); }
}

function renderMembers() {
    const wrap = $('voice-member-list');
    if (!allMembers.length) {
        wrap.innerHTML = '<div class="text-center py-3 text-muted">暂无成员</div>';
        return;
    }
    wrap.innerHTML = allMembers.map(m => {
        const online = onlineUids.has(m.id);
        const dotColor = online ? '#4caf50' : '#bdbdbd';
        const opacity = online ? 1 : 0.6;
        return `
        <div class="member-item p-2 border-bottom d-flex align-items-center" style="opacity:${opacity};">
            <div style="position:relative;">
                <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center text-white"
                     style="width:36px;height:36px;font-size:14px;">${(m.name||'?')[0]}</div>
                <span style="position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;border:2px solid #fff;background:${dotColor};"></span>
            </div>
            <div class="ms-2 flex-grow-1" style="font-size:13px;">
                <div class="fw-bold">${escapeHtml(m.name)}
                    ${m.is_host ? ' <span class="badge bg-success" style="font-size:10px;">主持</span>' : ''}
                    ${m.id === VOICE_STATE.currentPresenterId ? ' <span class="badge bg-danger" style="font-size:10px;">演示权</span>' : ''}</div>
            </div>
            ${VOICE_STATE.isHost && online && m.id !== VOICE_STATE.currentUserId && m.id !== VOICE_STATE.currentPresenterId ? `
                <button class="btn btn-sm btn-outline-primary" onclick="assignPresenter(${m.id})" title="设为演示者">
                    <i class="fa fa-arrow-right"></i>
                </button>` : ''}
        </div>`;
    }).join('');
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function updatePresenterLabel() {
    const badge = $('current-presenter-name');
    if (!VOICE_STATE.currentPresenterId) { badge.textContent = '未开始'; return; }
    const m = allMembers.find(x => x.id === VOICE_STATE.currentPresenterId);
    badge.textContent = m ? m.name : ('用户' + VOICE_STATE.currentPresenterId);
}

// ---------- 5. 演示权 ----------
function assignPresenter(uid) { sendSignal({ type: 'presenter_assign', to_uid: uid }); }
function showApplyBanner(uid, name) {
    const b = $('apply-banner');
    b.innerHTML = `${escapeHtml(name)} 申请演示
        <button class="btn btn-success btn-sm ms-2" onclick="approveApply(${uid})">批准</button>
        <button class="btn btn-secondary btn-sm ms-1" onclick="dismissApply()">忽略</button>`;
    b.classList.remove('d-none');
}
function dismissApply() { $('apply-banner').classList.add('d-none'); }
function approveApply(uid) { dismissApply(); assignPresenter(uid); }
$('btn-revoke-presenter')?.addEventListener('click', () => sendSignal({ type: 'presenter_revoke' }));
$('btn-apply-presenter')?.addEventListener('click', () => sendSignal({ type: 'presenter_apply' }));

// ---------- 6. OnlyOffice 文档 ----------
let currentDocId = null;
let currentDownloadUrl = null;

async function loadDoc(docId) {
    if (!docId) {
        $('doc-frame').style.display = 'none';
        $('doc-empty-tip').style.display = '';
        $('btn-download').disabled = true;
        return;
    }
    currentDocId = docId;
    const editMode = $('edit-mode').checked ? 1 : 0;
    const r = await fetch(`/project/${VOICE_STATE.projectId}/voice/doc-config/${docId}?edit=${editMode}`).then(r => r.json());
    $('doc-empty-tip').style.display = 'none';
    $('doc-frame').style.display = '';
    if (docEditor) { docEditor.destroy(); docEditor = null; }
    docEditor = new DocsAPI.DocEditor('doc-frame', { ...r.config, width: '100%', height: '100%' });
    currentDownloadUrl = r.download_url;
    $('btn-download').disabled = !currentDownloadUrl;
    startSlideWatcher();
}

let lastSentScroll = 0;
function startSlideWatcher() {
    setInterval(() => {
        if (VOICE_STATE.currentUserId !== VOICE_STATE.currentPresenterId) return;
        const frame = $('doc-frame');
        try {
            const inner = frame.contentDocument || frame.contentWindow.document;
            const el = inner.scrollingElement;
            if (!el) return;
            const scroll = el.scrollTop / (el.scrollHeight || 1);
            if (Math.abs(scroll - lastSentScroll) > 0.02) {
                lastSentScroll = scroll;
                sendSignal({ type: 'slide_sync', scroll });
            }
        } catch (e) {}
    }, 800);
}

function applySlideSync(msg) {
    const frame = $('doc-frame');
    try {
        const inner = frame.contentDocument || frame.contentWindow.document;
        const el = inner.scrollingElement;
        if (el && msg.scroll != null) el.scrollTop = msg.scroll * el.scrollHeight;
    } catch (e) {}
}

$('doc-select').addEventListener('change', (e) => {
    const v = e.target.value;
    if (!v) return;
    const docId = parseInt(v.split(':')[1]);
    sendSignal({ type: 'doc_select', doc_id: docId });
    loadDoc(docId);
});

// 上传本地文件
$('btn-upload').addEventListener('click', () => $('file-upload').click());
$('file-upload').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    try {
        const r = await fetch(`/project/${VOICE_STATE.projectId}/voice/upload-demo`, { method: 'POST', body: fd })
            .then(r => r.json());
        if (r.error) { alert(r.error); return; }
        sendSignal({ type: 'doc_select', doc_id: r.id });
        loadDoc(r.id);
    } catch (err) { alert('上传失败: ' + err.message); }
    e.target.value = '';
});

// 编辑模式切换：重新加载文档
$('edit-mode').addEventListener('change', () => { if (currentDocId) loadDoc(currentDocId); });

// 下载当前文档
$('btn-download').addEventListener('click', () => {
    if (currentDownloadUrl) window.open(currentDownloadUrl, '_blank');
});

$('btn-follow')?.addEventListener('click', () => {
    followingPresenter = true;
    $('btn-follow').disabled = true;
});

// ---------- 7. 加入/离开会议 ----------
$('btn-call').addEventListener('click', async () => {
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    } catch (e) {
        alert('无法访问麦克风：' + e.message);
        return;
    }
    await connectSignal();
    $('btn-call').disabled = true;
    $('btn-call').innerHTML = '<i class="fa fa-phone me-1"></i>会议中';
    $('btn-mic').disabled = false;
    $('btn-follow').disabled = false;
});

$('btn-mic').addEventListener('click', () => {
    const btn = $('btn-mic');
    const muted = btn.dataset.muted !== '1';
    btn.dataset.muted = muted ? '1' : '0';
    if (localStream) localStream.getAudioTracks().forEach(t => t.enabled = !muted);
    btn.innerHTML = muted ? '<i class="fa fa-microphone-slash"></i> 取消静音' : '<i class="fa fa-microphone"></i> 静音';
});

// 页面加载先拉成员列表
loadAllMembers();
</script>
@endsection
