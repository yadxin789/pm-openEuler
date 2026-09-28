<?php

namespace App\Http\Controllers;

use App\Models\WecomMessageLog;
use App\Services\WecomNotificationService;
use App\Services\WecomService;
use Illuminate\Http\Request;

class UserWecomController extends Controller
{
    protected WecomNotificationService $notificationService;
    protected WecomService $wecom;

    public function __construct(WecomNotificationService $notificationService, WecomService $wecom)
    {
        $this->notificationService = $notificationService;
        $this->wecom = $wecom;
    }

    /**
     * 用户个人 — 企业微信绑定管理页
     */
    public function index()
    {
        $user           = auth()->user();
        $isBound        = !empty($user->wecom_userid);
        $isWecomEnabled = $this->wecom->isEnabled();
        $isAdmin        = $user->isAdmin();
        $defaults       = $this->notificationService->getDefaultSettings();
        $userSettings   = $user->wecom_notify_settings
            ? json_decode($user->wecom_notify_settings, true)
            : $defaults;

        // 获取用户消息日志摘要
        $recentLogs = WecomMessageLog::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('profile.wecom.index', compact('user', 'isBound', 'isWecomEnabled', 'isAdmin', 'defaults', 'userSettings', 'recentLogs'));
    }

    /**
     * 用户个人 — 解除企业微信绑定
     */
    public function unbind()
    {
        $user                 = auth()->user();
        $user->wecom_userid   = null;
        $user->wecom_bound_at = null;
        $user->wecom_notify_enabled = false;
        $user->save();

        return back()->with('success', '已解除企业微信绑定');
    }

    /**
     * 用户个人 — 更新通知设置
     */
    public function updateSettings(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'notify_enabled' => 'boolean',
            'notify_types'   => 'nullable|array',
        ]);

        $user->wecom_notify_enabled = $request->boolean('notify_enabled');

        if ($request->has('notify_types')) {
            $settings = [];
            foreach (array_keys($this->notificationService->getDefaultSettings()) as $key) {
                $settings[$key] = in_array($key, $request->input('notify_types', []));
            }
            $user->wecom_notify_settings = json_encode($settings, JSON_UNESCAPED_UNICODE);
        }

        $user->save();

        return back()->with('success', '通知设置已更新');
    }
}
