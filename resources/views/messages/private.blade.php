@extends('layouts.app')

@section('content')
@php
    $userId = auth()->id() ?? Session::get('user_id');
    $currentUser = $userId ? \App\Models\User::find($userId) : null;
@endphp
<div class="container-fluid mt-3 px-0">
    <div class="row no-gutters" style="height: calc(100vh - 80px);">
        <!-- 聊天区域 -->
        <div class="col-12 px-0 d-flex flex-column" style="height: 100%;">
            <!-- 私聊头部 -->
            <div class="p-3 border-bottom bg-white d-flex align-items-center">
                <a href="{{ route('messages.index', $project->id) }}" class="btn btn-sm mr-3" style="background-color: #6c757d; color: white; border-color: #6c757d;">
                    <i class="fa fa-arrow-left mr-1"></i>返回群聊
                </a>
                <div class="d-flex align-items-center flex-grow-1">
                    <div class="mr-3">
                        @if($targetUser->avatar)
                            <img src="{{ $targetUserAvatar }}" class="rounded-circle" 
                                 style="width: 40px; height: 40px; object-fit: cover;">
                        @else
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white"
                                 style="width: 40px; height: 40px; font-size: 16px;">
                                {{ mb_substr($targetUser->username, 0, 1) }}
                            </div>
                        @endif
                    </div>
                    <div>
                        <h6 class="mb-0">{{ $targetUser->username }}</h6>
                        <small class="text-muted" id="target-user-status">{{ $isOnline ? '在线' : '离线' }}</small>
                    </div>
                </div>
                <form action="{{ route('messages.private.clear-mine', [$project->id, $targetUser->id]) }}" method="POST" class="mb-0" onsubmit="return confirm('确定清空你的私聊留言板吗？此操作只清空你自己的显示记录，不影响对方。')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fa fa-trash mr-1"></i>清空我的留言板
                    </button>
                </form>
            </div>
            @if(session('success'))
                <div class="alert alert-success py-1 mb-0 rounded-0" style="font-size: 13px;">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger py-1 mb-0 rounded-0" style="font-size: 13px;">{{ session('error') }}</div>
            @endif

            <!-- 私聊消息列表 -->
            <div class="flex-grow-1 overflow-auto p-3" style="background-color: #f5f5f5;" id="private-messages">
                @if($messages->isEmpty())
                    <div class="text-center text-muted py-5">
                        <i class="fa fa-comments-o fa-4x mb-3"></i>
                        <p>暂无聊天记录，发送第一条消息吧！</p>
                    </div>
                @else
                    <div class="chat-container">
                        @foreach($messages as $message)
                            @php
                                $isMyMessage = $message->user_id == $userId;
                                $avatarPath = str_starts_with($message->user->avatar, 'defaults/') 
                                    ? 'avatars/' . $message->user->avatar 
                                    : 'storage/avatars/' . $message->user->avatar;
                            @endphp
                            <div class="chat-message {{ $isMyMessage ? 'my-message' : 'other-message' }}">
                                <div class="message-avatar">
                                    @if($message->user->avatar)
                                        <img src="{{ asset($avatarPath) }}" alt="{{ $message->user->username }}" 
                                             class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">
                                    @else
                                        <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white" 
                                             style="width: 40px; height: 40px; font-size: 16px;">
                                            <span>{{ mb_substr($message->user->username, 0, 1) }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="message-content">
                                    <div class="message-info">
                                        <span class="message-username">{{ $message->user->username }}</span>
                                        <span class="message-time">{{ $message->created_at->format('H:i') }}</span>
                                    </div>
                                    <div class="message-bubble">
                                        {{ $message->content }}
                                    </div>
                                    @if($message->file_path)
                                        <div class="file-attachment" 
                                             data-file-url="{{ route('messages.file', $message->id) }}"
                                             data-file-name="{{ $message->file_name }}"
                                             ondblclick="previewFile(this)"
                                             oncontextmenu="downloadFile(event, this)">
                                            <i class="fa fa-file mr-2"></i>
                                            <span>{{ $message->file_name }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- 私聊输入区 -->
            <div class="p-3 border-top bg-white position-relative">
                <form id="private-chat-form" onsubmit="sendPrivateMessage(event)">
                    <!-- 第一行：按钮 -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center">
                            <button type="button" class="btn btn-sm px-4 mr-4" id="emoji-btn" title="表情" style="background-color: #ffc107; color: #212529; border-color: #ffc107;">
                                <i class="fa fa-smile-o mr-1"></i>表情
                            </button>
                            <label class="btn btn-sm px-4 mb-0" title="发送文件" style="cursor: pointer; background-color: #17a2b8; color: white; border-color: #17a2b8;" id="private-file-label">
                                <i class="fa fa-paperclip mr-1"></i>文件
                                <input type="file" name="file" id="private-file-input" style="display: none;">
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            <i class="fa fa-send mr-1"></i>发送
                        </button>
                    </div>
                    <!-- 第二行：文本输入框和文件预览 -->
                    <div class="border rounded" style="background-color: #fff;">
                        <!-- 文件预览（微信风格 - 在输入框内） -->
                        <div id="file-preview-area" class="p-2 border-bottom" style="display: none;">
                            <div class="d-flex align-items-center" style="font-size: 13px;">
                                <i class="fa fa-file mr-2 text-primary"></i>
                                <span id="file-preview-name" class="flex-grow-1"></span>
                                <button type="button" class="btn btn-xs btn-outline-danger border-0" onclick="clearPrivateFile()">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <!-- 文本输入框 -->
                        <div class="d-flex">
                            <textarea id="private-chat-input" class="form-control form-control-sm border-0" rows="2" 
                                      placeholder="输入私聊消息..." style="resize: none; box-shadow: none;"></textarea>
                        </div>
                    </div>
                </form>
                <!-- 表情面板 -->
                <div class="emoji-panel mt-2" id="emoji-panel" style="display: none;">
                    <div class="emoji-grid">
                        @foreach($emojis as $emoji)
                            <span class="emoji-item" onclick="insertEmoji('{{ $emoji }}')">{{ $emoji }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* === 聊天气泡样式 === */
.chat-container { padding: 0; }
.chat-message { display: flex; margin-bottom: 16px; align-items: flex-start; }
.chat-message.other-message { flex-direction: row; }
.chat-message.my-message { flex-direction: row-reverse; }
.message-avatar { flex-shrink: 0; }
.chat-message.other-message .message-avatar { margin-right: 8px; }
.chat-message.my-message .message-avatar { margin-left: 8px; }
.message-content { max-width: 65%; }
.message-info { display: flex; align-items: center; margin-bottom: 3px; font-size: 11px; color: #999; }
.chat-message.other-message .message-info { justify-content: flex-start; }
.chat-message.my-message .message-info { justify-content: flex-end; }
.message-username { font-weight: 600; margin-right: 6px; }
.message-time { font-size: 10px; }
.message-bubble {
    padding: 8px 14px; border-radius: 8px; word-wrap: break-word;
    line-height: 1.5; position: relative; font-size: 14px;
}
.chat-message.other-message .message-bubble { background-color: #fff; border: 1px solid #e8e8e8; }
.chat-message.my-message .message-bubble { background-color: #95ec69; border: 1px solid #86d65d; }

/* === 文件消息样式 === */
.file-attachment {
    display: flex; align-items: center; padding: 8px 12px;
    background: rgba(0,0,0,0.05); border-radius: 6px; margin-top: 4px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.file-attachment:hover {
    background: rgba(0,0,0,0.1);
}
.file-attachment.selected {
    background: #e3f2fd;
    border: 1px solid #90caf9;
}
.file-attachment i {
    color: #1976d2;
}

/* === Emoji 面板 === */
.emoji-panel {
    background-color: #fff; border: 1px solid #e5e5e5;
    border-radius: 8px; padding: 10px; max-height: 180px;
    overflow-y: auto; position: absolute; bottom: 100%;
    left: 0; right: 0; z-index: 1000;
    box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
}
.emoji-grid { display: grid; grid-template-columns: repeat(10, 1fr); gap: 4px; }
.emoji-item {
    font-size: 22px; cursor: pointer; text-align: center;
    padding: 4px; border-radius: 4px; transition: background-color 0.15s;
}
.emoji-item:hover { background-color: #f0f0f0; }
</style>

<script>
// ==================== 全局状态 ====================
const CURRENT_USER_ID = {{ $userId ?? 0 }};
const PROJECT_ID = {{ $project->id ?? 0 }};
const TARGET_USER_ID = {{ $targetUser->id ?? 0 }};
let selectedFile = null;

// ==================== 初始化 ====================
document.addEventListener('DOMContentLoaded', function() {
    // 滚动到底部
    const messagesContainer = document.getElementById('private-messages');
    if (messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // 表情按钮
    const emojiBtn = document.getElementById('emoji-btn');
    const emojiPanel = document.getElementById('emoji-panel');
    if (emojiBtn && emojiPanel) {
        emojiBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            emojiPanel.style.display = emojiPanel.style.display === 'none' ? 'block' : 'none';
        });
        document.addEventListener('click', function(e) {
            if (!emojiBtn.contains(e.target) && !emojiPanel.contains(e.target)) {
                emojiPanel.style.display = 'none';
            }
        });
    }
    
    // 私聊文件选择 - 通过 addEventListener 绑定，更可靠
    const fileInput = document.getElementById('private-file-input');
    const fileLabel = document.getElementById('private-file-label');
    if (fileInput) {
        fileInput.addEventListener('change', function(event) {
            handlePrivateFileSelect(event);
        });
    }
    // 确保点击 label 时能正确触发 file input（解决 display:none 的兼容问题）
    if (fileLabel && fileInput) {
        fileLabel.addEventListener('click', function(e) {
            // 如果点击的不是 input 本身，手动触发 input 点击
            if (e.target !== fileInput) {
                e.preventDefault();
                fileInput.click();
            }
        });
    }
});

// ==================== 发送消息 ====================
function sendPrivateMessage(event) {
    event.preventDefault();
    
    const input = document.getElementById('private-chat-input');
    const content = input.value.trim();
    
    if (!content && !selectedFile) {
        return;
    }
    
    const formData = new FormData();
    formData.append('receiver_id', TARGET_USER_ID);
    formData.append('content', content);
    if (selectedFile) {
        formData.append('file', selectedFile);
    }
    
    const submitBtn = document.querySelector('#private-chat-form button[type="submit"]');
    submitBtn.disabled = true;
    
    fetch(`/api/project/${PROJECT_ID}/chat/send`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json',
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.message) {
            const container = document.getElementById('private-messages');
            if (container.querySelector('.text-muted')) {
                container.innerHTML = '<div class="chat-container"></div>';
            }
            container.innerHTML += renderMessage(data.message);
            container.scrollTop = container.scrollHeight;
            
            input.value = '';
            clearPrivateFile();
        } else if (data.error) {
            alert(data.error);
        }
    })
    .catch(err => {
        console.error('发送失败:', err);
        alert('发送失败，请重试');
    })
    .finally(() => {
        submitBtn.disabled = false;
        input.focus();
    });
}

function renderMessage(msg) {
    const msgClass = msg.is_my_message ? 'my-message' : 'other-message';
    const avatarHtml = msg.avatar
        ? `<img src="${msg.avatar}" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">`
        : `<div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white"
              style="width: 40px; height: 40px; font-size: 16px;">${msg.username.charAt(0)}</div>`;
    
    let contentHtml = '';
    if (msg.content) {
        contentHtml += `<div class="message-bubble">${escapeHtml(msg.content)}</div>`;
    }
    if (msg.file_url) {
        contentHtml += `
            <div class="file-attachment" 
                 data-file-url="${msg.file_url}"
                 data-file-name="${msg.file_name || '文件'}"
                 ondblclick="previewFile(this)"
                 oncontextmenu="downloadFile(event, this)">
                <i class="fa fa-file mr-2"></i>
                <span>${escapeHtml(msg.file_name || '文件')}</span>
            </div>`;
    }
    
    return `
        <div class="chat-message ${msgClass}">
            <div class="message-avatar">${avatarHtml}</div>
            <div class="message-content">
                <div class="message-info">
                    <span class="message-username">${escapeHtml(msg.username)}</span>
                    <span class="message-time">${msg.created_at}</span>
                </div>
                ${contentHtml}
            </div>
        </div>`;
}

// ==================== 文件发送 ====================
function handlePrivateFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    console.log('[私聊文件] 选择了文件:', file.name, '大小:', file.size);
    
    if (file.size > 20 * 1024 * 1024) {
        alert('文件大小不能超过 20MB');
        event.target.value = '';
        return;
    }
    
    selectedFile = file;
    
    // 更新文件预览区
    const previewName = document.getElementById('file-preview-name');
    const previewArea = document.getElementById('file-preview-area');
    
    if (previewName) {
        previewName.textContent = file.name;
        console.log('[私聊文件] 预览名称已设置:', file.name);
    } else {
        console.error('[私聊文件] 找不到 file-preview-name 元素');
    }
    
    if (previewArea) {
        previewArea.style.display = 'block';
        console.log('[私聊文件] 预览区域已显示');
    } else {
        console.error('[私聊文件] 找不到 file-preview-area 元素');
    }
}

function clearPrivateFile() {
    selectedFile = null;
    const fileInput = document.getElementById('private-file-input');
    const previewArea = document.getElementById('file-preview-area');
    if (fileInput) fileInput.value = '';
    if (previewArea) previewArea.style.display = 'none';
}

// ==================== 文件消息交互 ====================
// 双击预览文件（仅支持预览的格式才响应）
function previewFile(element) {
    const fileUrl = element.dataset.fileUrl;
    const fileName = element.dataset.fileName;
    
    // 判断文件类型进行预览
    const ext = fileName.split('.').pop().toLowerCase();
    const previewableDocs = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    const previewableImages = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'];
    
    if (previewableDocs.includes(ext)) {
        // 文档类型在新窗口打开预览
        window.open(fileUrl, '_blank');
    } else if (previewableImages.includes(ext)) {
        // 图片类型显示在弹窗中预览
        showImagePreview(fileUrl, fileName);
    }
    // 不支持预览的格式：不响应双击事件（用户可通过右键下载）
}

// 右键下载文件
function downloadFile(event, element) {
    event.preventDefault(); // 阻止默认右键菜单
    
    const fileUrl = element.dataset.fileUrl;
    const fileName = element.dataset.fileName;
    
    // 添加下载参数
    const downloadUrl = fileUrl + (fileUrl.includes('?') ? '&' : '?') + 'download=1';
    
    // 创建下载链接
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// 显示图片预览
function showImagePreview(src, title) {
    // 创建预览弹窗
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        cursor: pointer;
    `;
    
    const img = document.createElement('img');
    img.src = src;
    img.title = title;
    img.style.cssText = `
        max-width: 90%;
        max-height: 90%;
        object-fit: contain;
        border-radius: 8px;
    `;
    
    const closeBtn = document.createElement('div');
    closeBtn.innerHTML = '<i class="fa fa-times" style="color: white; font-size: 24px;"></i>';
    closeBtn.style.cssText = `
        position: absolute;
        top: 20px;
        right: 30px;
        width: 40px;
        height: 40px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    overlay.appendChild(img);
    overlay.appendChild(closeBtn);
    document.body.appendChild(overlay);
    
    // 点击关闭
    overlay.addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
}

// ==================== Emoji ====================
function insertEmoji(emoji) {
    const input = document.getElementById('private-chat-input');
    const start = input.selectionStart;
    const end = input.selectionEnd;
    input.value = input.value.substring(0, start) + emoji + input.value.substring(end);
    input.selectionStart = input.selectionEnd = start + emoji.length;
    input.focus();
}

// ==================== 工具函数 ====================
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
@endsection
