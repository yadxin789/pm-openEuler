<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('notification.index', compact('notifications'));
    }

    public function markAsRead(Notification $notification)
    {
        if ($notification->user_id != auth()->id()) {
            abort(403);
        }

        $notification->markAsRead();
        return redirect()->back()->with('success', '通知已标记为已读');
    }

    public function markAllAsRead()
    {
        Notification::where('user_id', auth()->id())->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return redirect()->back()->with('success', '所有通知已标记为已读');
    }

    public function destroy(Notification $notification)
    {
        // 验证权限：只能删除自己的通知
        if ($notification->user_id != auth()->id()) {
            abort(403, '您没有权限删除此通知');
        }

        $notification->delete();
        return redirect()->back()->with('success', '通知已删除，可随时在回收站中恢复');
    }
}