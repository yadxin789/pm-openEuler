@extends('layouts.app')

@section('title', '鐢佃瘽浼氳 - ' . $project->name)

@section('content')
@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'voice'])

<div class="row no-gutters" style="height: calc(100vh - 220px);">
    {{-- 宸︿晶锛氶」鐩垚鍛樺垪琛?--}}
    <div class="col-md-3 col-lg-2 px-0 d-flex flex-column"
         style="border-right: 1px solid #dee2e6; background: #f8f9fa; max-height: calc(100vh - 220px);">
        <div class="p-3 border-bottom bg-white">
            <h6 class="mb-1 text-truncate"><i class="fa fa-phone text-primary me-1"></i>鐢佃瘽浼氳</h6>
            <small>
                @if($isHost)
                    <span class="badge bg-success"><i class="fa fa-star me-1"></i>浣犳槸涓绘寔浜?/span>
                @else
                    <span class="badge bg-secondary">鍚紬</span>
                @endif
            </small>
        </div>
        <div class="px-3 py-2 border-bottom" style="font-size:12px;color:#999;">椤圭洰鎴愬憳</div>
        <div class="flex-grow-1 overflow-y-auto" id="voice-member-list">
            <div class="text-center py-4 text-muted" style="font-size:13px;">
                <i class="fa fa-spinner fa-spin"></i> 鍔犺浇涓?..
            </div>
        </div>
        <div class="p-2 border-top bg-white">
            <button id="btn-apply-presenter" class="btn btn-outline-primary btn-sm w-100" {{ $isHost ? 'disabled' : '' }}>
                <i class="fa fa-hand-paper-o me-1"></i>鐢宠婕旂ず
            </button>
        </div>
    </div>

    {{-- 鍙充晶锛氫富灞曠ず鍖?--}}
    <div class="col-md-9 col-lg-10 d-flex flex-column" style="max-height: calc(100vh - 220px);">
        <div class="d-flex align-items-center justify-content-between p-2 border-bottom bg-white">
            <div>
                褰撳墠婕旂ず鑰咃細<span id="current-presenter-name" class="fw-bold">鏈紑濮?/span>
                <span id="apply-banner" class="ms-3 alert alert-warning py-1 px-2 mb-0 d-none"></span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <select id="doc-select" class="form-select form-select-sm" style="width:260px;">
                    <option value="">-- 閫夋嫨婕旂ず鏂囨。 --</option>
                    @foreach($documents as $doc)
                        <option value="{{ $doc->id }}">{{ $doc->name }}.{{ $doc->extension }}</option>
                    @endforeach
                </select>
                @if($isHost)
                    <button id="btn-revoke-presenter" class="btn btn-sm btn-outline-danger">
                        <i class="fa fa-undo me-1"></i>鏀跺洖婕旂ず鏉?                    </button>
                @endif
            </div>
        </div>

        <div class="flex-grow-1 bg-light d-flex align-items-center justify-content-center" id="doc-frame-wrap">
            <div class="text-center text-muted" id="doc-empty-tip">
                <i class="fa fa-file-text-o" style="font-size:48px;"></i>
                <p class="mt-2">涓绘寔浜轰负浣犻€夋嫨涓€绡囨枃妗ｅ悗锛岃繖閲屽皢灞曠ず鍙鍗忓悓鍔炲叕椤甸潰</p>
            </div>
            <iframe id="doc-frame" style="display:none; width:100%; height:100%; border:0; background:#fff;"></iframe>
        </div>

        <div class="p-2 border-top bg-white d-flex justify-content-center gap-3">
            <button id="btn-mic" class="btn btn-secondary" disabled>
                <i class="fa fa-microphone"></i> 闈欓煶
            </button>
            <button id="btn-call" class="btn btn-primary">
                <i class="fa fa-phone me-1"></i>鍔犲叆浼氳
            </button>
            <button id="btn-follow" class="btn btn-outline-secondary" disabled>
                <i class="fa fa-crosshairs me-1"></i>鍥炲埌婕旂ず
            </button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('vendor/janus/janus.js') }}"></script>
<script>
window.VOICE_STATE = {
    projectId: {{ $project->id }},
    projectCode: @json($project->code),
    roomId: {{ $room->id }},
    isHost: @json($isHost),
    currentUserId: {{ auth()->id() }},
    currentPresenterId: {{ $room->current_presenter_id ?? 'null' }},
};

const $ = (id) => document.getElementById(id);
let ws = null;
let janusHandle = null;       // Janus AudioBridge handle
let myStream = null;
let followingPresenter = true; // 鍚紬鏄惁璺熼殢 presenter 浣嶇疆

// ---------- 1. 杩炴帴 Swoole 淇′护 ----------
async function connectSignal() {
    const r = await fetch(`/project/${VOICE_STATE.projectId}/voice/token`).then(r => r.json());
    const url = `${r.signal_ws.replace(/^http/, 'ws')}?token=${encodeURIComponent(r.token)}`;
    window._voiceConf = r;

    ws = new WebSocket(url);
    ws.onopen = () => console.log('[voice] signal ws open');
    ws.onclose = () => console.log('[voice] signal ws closed, retry in 3s');
    ws.onerror = (e) => console.error('[voice] ws error', e);
    ws.onmessage = (e) => handleSignal(JSON.parse(e.data));
}

function sendSignal(obj) {
    if (ws && ws.readyState === 1) ws.send(JSON.stringify(obj));
}

// ---------- 2. 淇′护娑堟伅澶勭悊 ----------
function handleSignal(msg) {
    switch (msg.type) {
        case 'room_state':
            renderMembers(msg.members);
            break;
        case 'presenter_changed':
            VOICE_STATE.currentPresenterId = msg.presenter_uid;
            updatePresenterLabel();
            break;
        case 'presenter_apply':
            // host 鏀跺埌锛氭樉绀烘壒鍑嗘í骞?            if (VOICE_STATE.isHost) {
                showApplyBanner(msg.from_uid, msg.from_name);
            }
            break;
        case 'doc_selected':
            loadDoc(msg.doc_id);
            break;
        case 'slide_sync':
            if (!followingPresenter) break;
            applySlideSync(msg);
            break;
        case 'error':
            alert('淇′护閿欒: ' + msg.reason);
            break;
    }
}

// ---------- 3. 鎴愬憳鍒楄〃 ----------
function renderMembers(members) {
    const wrap = $('voice-member-list');
    if (!members.length) { wrap.innerHTML = '<div class="text-center py-3 text-muted">鏆傛棤鎴愬憳</div>'; return; }
    wrap.innerHTML = members.map(m => `
        <div class="member-item p-2 border-bottom d-flex align-items-center" style="position:relative;">
            <div style="position:relative;">
                <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center text-white"
                     style="width:36px;height:36px;font-size:14px;">${m.name[0] || '?'}</div>
                <span class="online-dot ${m.uid === VOICE_STATE.currentUserId ? 'online' : 'online'}"
                      style="position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;border:2px solid #fff;background:#4caf50;"></span>
            </div>
            <div class="ms-2 flex-grow-1" style="font-size:13px;">
                <div class="fw-bold">${escapeHtml(m.name)}${m.is_host ? ' <span class="badge bg-success" style="font-size:10px;">涓绘寔</span>' : ''}
                    ${m.uid === VOICE_STATE.currentPresenterId ? ' <span class="badge bg-primary" style="font-size:10px;">婕旂ず</span>' : ''}</div>
            </div>
            ${VOICE_STATE.isHost && m.uid !== VOICE_STATE.currentUserId && m.uid !== VOICE_STATE.currentPresenterId ? `
                <button class="btn btn-sm btn-outline-primary" onclick="assignPresenter(${m.uid})" title="璁句负婕旂ず鑰?>
                    <i class="fa fa-arrow-right"></i>
                </button>` : ''}
        </div>
    `).join('');
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function updatePresenterLabel() {
    // 浠庢垚鍛樺垪琛ㄦ壘鍚嶅瓧
    const badge = $('current-presenter-name');
    if (!VOICE_STATE.currentPresenterId) { badge.textContent = '鏈紑濮?; return; }
    // 鎴愬憳鍒楄〃宸叉覆鏌擄紝閬嶅巻鎵?    const el = [...document.querySelectorAll('#voice-member-list .fw-bold')]
        .find(e => e.textContent.includes('婕旂ず'));
    badge.textContent = el ? el.textContent.replace(/婕旂ず|涓绘寔/g,'').trim() : ('鐢ㄦ埛' + VOICE_STATE.currentPresenterId);
}

// ---------- 4. 婕旂ず鏉冧氦浜?----------
function assignPresenter(uid) {
    sendSignal({ type: 'presenter_assign', to_uid: uid });
}

function showApplyBanner(uid, name) {
    const banner = $('apply-banner');
    banner.innerHTML = `${escapeHtml(name)} 鐢宠婕旂ず
        <button class="btn btn-success btn-sm ms-2" onclick="approveApply(${uid})">鎵瑰噯</button>
        <button class="btn btn-secondary btn-sm ms-1" onclick="dismissApply()">蹇界暐</button>`;
    banner.classList.remove('d-none');
}
function dismissApply() { $('apply-banner').classList.add('d-none'); }
function approveApply(uid) { dismissApply(); assignPresenter(uid); }

$('btn-revoke-presenter')?.addEventListener('click', () => sendSignal({ type: 'presenter_revoke' }));
$('btn-apply-presenter')?.addEventListener('click', () => sendSignal({ type: 'presenter_apply' }));

// ---------- 5. OnlyOffice 鏂囨。鍔犺浇 + 缈婚〉鍚屾 ----------
let docEditor = null;
async function loadDoc(docId) {
    if (!docId) {
        $('doc-frame').style.display = 'none';
        $('doc-empty-tip').style.display = '';
        return;
    }
    const r = await fetch(`/project/${VOICE_STATE.projectId}/voice/doc-config/${docId}`).then(r => r.json());
    $('doc-empty-tip').style.display = 'none';
    $('doc-frame').style.display = '';

    // 閿€姣佹棫 editor
    if (docEditor) { docEditor.destroy(); docEditor = null; }
    new DocsAPI.DocEditor('doc-frame', {
        ...r.config,
        width: '100%',
        height: '100%',
        events: {},
    });
    docEditor = window.docEditor;
    startSlideWatcher();
}

// presenter 绔細杞褰撳墠婊氬姩/椤电爜锛屽彉鍖栬秴杩囬槇鍊兼墠骞挎挱
let lastSent = { page: 0, scroll: 0 };
function startSlideWatcher() {
    setInterval(() => {
        if (VOICE_STATE.currentUserId !== VOICE_STATE.currentPresenterId) return;
        const frame = $('doc-frame');
        let scroll = 0, page = 0;
        try {
            const inner = frame.contentDocument || frame.contentWindow.document;
            scroll = inner.scrollingElement ? inner.scrollingElement.scrollTop / (inner.scrollingElement.scrollHeight || 1) : 0;
        } catch (e) { /* 璺ㄥ煙鏃剁敱 OnlyOffice API 琛?*/ }
        if (Math.abs(scroll - lastSent.scroll) > 0.02) {
            lastSent = { page, scroll };
            sendSignal({ type: 'slide_sync', page, scroll });
        }
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
    const docId = parseInt(e.target.value);
    sendSignal({ type: 'doc_select', doc_id: docId });
    loadDoc(docId);
});

$('btn-follow')?.addEventListener('click', () => {
    followingPresenter = true;
    $('btn-follow').disabled = true;
});

// ---------- 6. Janus AudioBridge ----------
async function joinAudio() {
    const conf = window._voiceConf;
    Janus.init({
        debug: false,
        callback: () => {
            const janus = new Janus({
                server: conf.janus_ws.replace('ws://', 'http://').replace('wss://','https://'),
                success: () => {
                    janus.attachPlugin({
                        plugin: 'janus.plugin.audiobridge',
                        success: (handle) => {
                            janusHandle = handle;
                            // TODO: join room (room id = voice_rooms.janus_room_id), publish mic
                        },
                        error: (e) => console.error('[janus] attach error', e),
                    });
                },
                error: (e) => console.error('[janus] init error', e),
            });
        },
    });
}

$('btn-call').addEventListener('click', async () => {
    await connectSignal();
    await joinAudio();
    $('btn-call').disabled = true;
    $('btn-call').innerHTML = '<i class="fa fa-phone me-1"></i>浼氳涓?;
    $('btn-mic').disabled = false;
    $('btn-follow').disabled = false;
});

$('btn-mic').addEventListener('click', () => {
    const btn = $('btn-mic');
    const muted = btn.dataset.muted !== '1';
    btn.dataset.muted = muted ? '1' : '0';
    btn.innerHTML = muted ? '<i class="fa fa-microphone-slash"></i> 鍙栨秷闈欓煶' : '<i class="fa fa-microphone"></i> 闈欓煶';
    // TODO: janusHandle.send({message: {request: 'unpublish'}}) / publish
});

// 鍚姩鏃跺厛杩炰俊浠ゆ嬁鎴愬憳鍒楄〃锛堜笉绔嬪嵆鍏ヤ細锛岀瓑鐢ㄦ埛鐐?鍔犲叆浼氳"锛?connectSignal();
</script>
@endsection
