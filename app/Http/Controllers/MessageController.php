<?php

namespace App\Http\Controllers;

use App\Models\ChatClearState;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    // 显示项目留言板
    public function index($projectId)
    {
        $userId = auth()->id();
        
        $project = Project::findOrFail($projectId);
        $user = auth()->user();
        
        // 检查用户是否有权访问该项目（参与项目、项目总经理或管理员）
        $participants = $project->participants();
        $hasAccess = $participants->contains($user) || $user->isProjectManager() || $user->isAdmin();
        
        if (!$hasAccess) {
            return redirect()->back()->with('error', '您没有权限访问该项目的留言板');
        }

        $messagesQuery = Message::where('project_id', $projectId)
            ->group()
            ->with('user')
            ->orderBy('created_at', 'asc');

        // 清空留言板只影响当前用户自己的显示范围，不删除真实聊天记录。
        $clearState = ChatClearState::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('chat_type', 'group')
            ->where('conversation_key', 'group')
            ->first();
        if ($clearState) {
            $messagesQuery->where('created_at', '>', $clearState->cleared_at);
        }

        $messages = $messagesQuery->get();

        // 表情列表（常用emoji）
        $emojis = [
            // 笑脸类
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
            // 手指类
            '👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙',
            '👈', '👉', '👆', '👇', '☝️', '👋', '🤚', '🖐️',
            '✋', '🖖', '👏', '🙌', '👐', '🤲', '🤝', '🙏',
            '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃',
            '🧠', '🫀', '🫁', '🦷', '🦴', '👀', '👁️', '👅',
        ];

        return view('messages.index', compact('project', 'messages', 'emojis'));
    }

    // 发布留言
    public function store(Request $request, $projectId)
    {
        $userId = auth()->id();

        $request->validate([
            'content' => 'nullable|string|max:1000',
            'file' => 'nullable|file|max:20480', // 最大20MB
        ]);

        $project = Project::findOrFail($projectId);
        $user = auth()->user();

        // 检查用户是否有权访问该项目（参与项目、项目总经理或管理员）
        $participants = $project->participants();
        $hasAccess = $participants->contains($user) || $user->isProjectManager() || $user->isAdmin();
        
        if (!$hasAccess) {
            return redirect()->back()->with('error', '您没有权限在该项目留言');
        }

        $data = [
            'project_id' => $projectId,
            'user_id' => $userId,
            'content' => $request->content ?? '',
        ];

        // 处理文件上传（统一存储到 chat_files/{projectId}/ 目录）
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('chat_files/' . $projectId, 'public');
            
            $data['file_path'] = $filePath;
            $data['file_name'] = $fileName;
        }

        Message::create($data);

        return redirect()->route('messages.index', $projectId);
    }

    // 管理员删除留言
    public function destroy($projectId, $id)
    {
        $userId = auth()->id();

        $message = Message::findOrFail($id);
        
        // 仅管理员可以删除留言
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            return redirect()->back()->with('error', '只有管理员可以删除留言');
        }

        $projectId = $message->project_id;

        // 删除关联的物理文件
        if ($message->file_path) {
            Storage::disk('public')->delete($message->file_path);
        }

        $message->delete();

        return redirect()->route('messages.index', $projectId)->with('success', '留言已删除');
    }

    // 清空当前用户在指定项目中的群聊留言板显示记录
    public function clearMine($projectId)
    {
        $user = auth()->user();
        $project = Project::findOrFail($projectId);

        // 与进入留言板保持同一套权限判断。
        $participants = $project->participants();
        $hasAccess = $participants->contains($user) || $user->isProjectManager() || $user->isAdmin();

        if (!$hasAccess) {
            return redirect()->back()->with('error', '您没有权限清空该项目的留言板');
        }

        ChatClearState::updateOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $projectId,
                'chat_type' => 'group',
                'conversation_key' => 'group',
            ],
            [
                'other_user_id' => null,
                'cleared_at' => now(),
            ]
        );

        return redirect()->route('messages.index', $projectId)->with('success', '已清空你的群聊留言板');
    }

    /**
     * 聊天文件预览/下载（群聊和私聊通用）
     * 通过 Message ID 从数据库查找文件路径，校验权限后流式输出
     */
    public function file($id)
    {
        $message = Message::findOrFail($id);

        if (!$message->file_path) {
            abort(404, '该消息没有附件');
        }

        // 权限校验：只有项目参与者、项目总经理或管理员可以访问
        $user = auth()->user();
        $project = Project::findOrFail($message->project_id);
        $participants = $project->participants();
        $hasAccess = $participants->contains($user) || $user->isProjectManager() || $user->isAdmin();

        if (!$hasAccess) {
            abort(403, '您没有权限访问该文件');
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($message->file_path)) {
            abort(404, '文件不存在或已被删除');
        }

        // 根据请求参数决定预览还是下载
        $disposition = request()->has('download') ? 'attachment' : 'inline';

        return $disk->download(
            $message->file_path,
            $message->file_name,
            ['Content-Disposition' => $disposition . '; filename="' . $message->file_name . '"']
        );
    }
}
