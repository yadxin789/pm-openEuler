<?php

namespace App\Http\Controllers;

use App\Models\ChatClearState;
use App\Models\Message;
use App\Models\Project;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;

class PrivateMessageController extends Controller
{
    /**
     * 显示私聊页面
     */
    public function show($projectId, $targetUserId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return redirect()->route('login')->with('error', '请先登录');
        }

        $project = Project::findOrFail($projectId);
        $targetUser = \App\Models\User::findOrFail($targetUserId);
        $currentUser = auth()->user();

        // 检查用户是否有权访问该项目（参与项目、项目总经理或管理员）
        $participants = $project->participants();
        $hasAccess = $participants->contains('id', $userId) || 
                     ($currentUser && ($currentUser->isProjectManager() || $currentUser->isAdmin()));
        
        if (!$hasAccess) {
            return redirect()->back()->with('error', '您没有权限访问该项目');
        }

        // 获取私聊记录。清空留言板只过滤当前用户清空时间之前的显示内容。
        $messagesQuery = Message::where('project_id', $projectId)
            ->where(function ($query) use ($userId, $targetUserId) {
                $query->where('user_id', $userId)->where('receiver_id', $targetUserId)
                      ->orWhere('user_id', $targetUserId)->where('receiver_id', $userId);
            })
            ->with(['user', 'receiver'])
            ->orderBy('created_at', 'asc');

        $clearState = ChatClearState::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('chat_type', 'private')
            ->where('conversation_key', $this->privateConversationKey($targetUserId))
            ->first();
        if ($clearState) {
            $messagesQuery->where('created_at', '>', $clearState->cleared_at);
        }

        $messages = $messagesQuery->get();

        // 判断对方是否在线
        $isOnline = $this->isUserOnline($targetUser);

        // 构建目标用户头像路径
        $targetUserAvatar = null;
        if ($targetUser->avatar) {
            $targetUserAvatar = str_starts_with($targetUser->avatar, 'defaults/')
                ? asset('avatars/' . $targetUser->avatar)
                : asset('storage/avatars/' . $targetUser->avatar);
        }

        // 表情列表
        $emojis = [
            '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂',
            '🙂', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗',
            '😚', '😙', '🥲', '😋', '😛', '😜', '🤪', '😝',
            '🤑', '🤗', '🤭', '🤫', '🤔', '🤐', '🤨', '😐',
            '😑', '😶', '😏', '😒', '🙄', '😬', '🤥', '😌',
            '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🤢',
            '🤮', '🥵', '🥶', '🥴', '😵', '🤯', '🤠', '🥳',
            '🥸', '😎', '🤓', '🧐', '😕', '😟', '🙁', '☹️',
            '😮‍💨', '😥', '😢', '😭', '😱', '😖', '😣', '😞',
            '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬',
            '😈', '👿', '💀', '☠️', '💩', '🤡', '👹', '👺',
            '👻', '👽', '👾', '🤖', '🎃', '😺', '😸', '😹',
            '😻', '😼', '😽', '🙀', '😿', '😾', '💋', '👄',
            '👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙',
            '👈', '👉', '👆', '👇', '☝️', '👋', '🤚', '🖐️',
            '✋', '🖖', '👏', '🙌', '👐', '🤲', '🤝', '🙏',
            '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃',
            '🧠', '🫀', '🫁', '🦷', '🦴', '👀', '👁️', '👅',
        ];

        return view('messages.private', compact(
            'project', 
            'targetUser', 
            'targetUserAvatar',
            'messages', 
            'isOnline', 
            'emojis'
        ));
    }

    /**
     * 获取项目成员列表（含在线状态模拟）
     */
    public function members($projectId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['error' => '未登录'], 401);
        }

        $project = Project::findOrFail($projectId);
        $user = auth()->user();

        $participants = $project->participants();
        $hasAccess = $participants->contains('id', $userId) || 
                     ($user && ($user->isProjectManager() || $user->isAdmin()));
        if (!$hasAccess) {
            return response()->json(['error' => '无权限'], 403);
        }

        // 重新查询成员列表，包含角色信息
        $memberIds = $participants->pluck('id');
        $members = \App\Models\User::whereIn('id', $memberIds)
            ->with('role')
            ->get()
            ->map(function ($member) use ($userId) {
                // 模拟在线状态：5分钟内活跃的用户视为在线
                $isOnline = $this->isUserOnline($member);
                
                $avatarPath = null;
                if ($member->avatar) {
                    $avatarPath = str_starts_with($member->avatar, 'defaults/')
                        ? asset('avatars/' . $member->avatar)
                        : asset('storage/avatars/' . $member->avatar);
                }

                return [
                    'id' => $member->id,
                    'username' => $member->username,
                    'avatar' => $avatarPath,
                    'is_online' => $isOnline,
                    'is_me' => $member->id == $userId,
                ];
            });

        return response()->json(['members' => $members]);
    }

    /**
     * 获取与指定用户的私聊记录
     */
    public function history($projectId, $targetUserId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['error' => '未登录'], 401);
        }

        $messagesQuery = Message::where('project_id', $projectId)
            ->between($userId, $targetUserId)
            ->with(['user', 'receiver'])
            ->orderBy('created_at', 'asc');

        // 历史接口同样只隐藏当前用户已清空时间点之前的私聊记录。
        $clearState = ChatClearState::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('chat_type', 'private')
            ->where('conversation_key', $this->privateConversationKey($targetUserId))
            ->first();
        if ($clearState) {
            $messagesQuery->where('created_at', '>', $clearState->cleared_at);
        }

        $messages = $messagesQuery->get()
            ->map(function ($msg) use ($userId) {
                $avatarPath = null;
                if ($msg->user->avatar) {
                    $avatarPath = str_starts_with($msg->user->avatar, 'defaults/')
                        ? asset('avatars/' . $msg->user->avatar)
                        : asset('storage/avatars/' . $msg->user->avatar);
                }

                return [
                    'id' => $msg->id,
                    'content' => $msg->content,
                    'user_id' => $msg->user_id,
                    'username' => $msg->user->username,
                    'avatar' => $avatarPath,
                    'is_my_message' => $msg->user_id == $userId,
                    'file_url' => $msg->file_path ? route('messages.file', $msg->id) : null,
                    'file_name' => $msg->file_name,
                    'created_at' => $msg->created_at->format('H:i'),
                    'full_time' => $msg->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json(['messages' => $messages]);
    }

    /**
     * 清空当前用户与指定成员在当前项目中的私聊留言板显示记录
     */
    public function clearMine($projectId, $targetUserId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return redirect()->route('login')->with('error', '请先登录');
        }

        $project = Project::findOrFail($projectId);
        $targetUser = \App\Models\User::findOrFail($targetUserId);
        $currentUser = auth()->user();

        // 与私聊页面保持同一套项目访问权限判断。
        $participants = $project->participants();
        $hasAccess = $participants->contains('id', $userId) ||
                     ($currentUser && ($currentUser->isProjectManager() || $currentUser->isAdmin()));

        if (!$hasAccess) {
            return redirect()->back()->with('error', '您没有权限清空该项目的私聊留言板');
        }

        ChatClearState::updateOrCreate(
            [
                'user_id' => $userId,
                'project_id' => $projectId,
                'chat_type' => 'private',
                'conversation_key' => $this->privateConversationKey($targetUserId),
            ],
            [
                'other_user_id' => $targetUser->id,
                'cleared_at' => now(),
            ]
        );

        return redirect()->route('messages.private', [$projectId, $targetUserId])->with('success', '已清空你的私聊留言板');
    }

    /**
     * 发送私聊消息
     */
    public function send(Request $request, $projectId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['error' => '未登录'], 401);
        }

        $request->validate([
            'receiver_id' => 'required|integer|exists:users,id',
            'content' => 'nullable|string|max:1000',
        ]);

        if (empty($request->content) && !$request->hasFile('file')) {
            return response()->json(['error' => '消息内容或文件不能同时为空'], 422);
        }

        $filePath = null;
        $fileName = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('chat_files/' . $projectId, 'public');
        }

        $message = Message::create([
            'project_id' => $projectId,
            'user_id' => $userId,
            'receiver_id' => $request->receiver_id,
            'is_private' => true,
            'content' => $request->content ?? '',
            'file_path' => $filePath,
            'file_name' => $fileName,
        ]);

        $message->load(['user', 'receiver']);
        $user = $message->user;

        // 如果对方不在线，向对方发送通知
        $receiver = $message->receiver;
        if ($receiver && !$this->isUserOnline($receiver)) {
            $project = Project::find($projectId);
            $contentPreview = mb_strlen($message->content) > 50
                ? mb_substr($message->content, 0, 50) . '...'
                : ($message->content ?: ($message->file_name ? '[文件] ' . $message->file_name : '[新消息]'));

            $receiver->notify(new SystemNotification(
                title: '收到新的私聊消息',
                content: "用户「{$user->username}」在项目「{$project->name}」中向您发送了一条私聊消息：{$contentPreview}",
                relatedType: 'project',
                relatedId: $projectId,
                senderId: $userId,
                type: 'private_message',
            ));
        }

        $avatarPath = null;
        if ($user->avatar) {
            $avatarPath = str_starts_with($user->avatar, 'defaults/')
                ? asset('avatars/' . $user->avatar)
                : asset('storage/avatars/' . $user->avatar);
        }

        return response()->json([
            'message' => [
                'id' => $message->id,
                'content' => $message->content,
                'user_id' => $message->user_id,
                'username' => $user->username,
                'avatar' => $avatarPath,
                'is_my_message' => true,
                'file_url' => $message->file_path ? route('messages.file', $message->id) : null,
                'file_name' => $message->file_name,
                'created_at' => $message->created_at->format('H:i'),
                'full_time' => $message->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * 判断用户在线状态
     * 使用 session 最后活动时间判断，5分钟内有活动视为在线
     * 实际项目中可替换为 Redis/WebSocket 实时状态
     */
    private function isUserOnline($user): bool
    {
        $currentUserId = auth()->id();
        // 当前登录用户始终显示在线
        if ($user->id == $currentUserId) {
            return true;
        }
        
        // 其他用户：使用 session 最后活动时间（如果存在）
        $lastSeen = session('user_last_seen_' . $user->id);
        if ($lastSeen) {
            return (time() - $lastSeen) < 300; // 5分钟内
        }

        // 如果没有活动记录，视为离线
        return false;
    }

    /**
     * 生成当前用户视角下的私聊会话键，用于区分同一项目内不同私聊对象。
     */
    private function privateConversationKey($targetUserId): string
    {
        return 'user_' . $targetUserId;
    }
}
