<?php

namespace App\Http\Controllers;

use App\Models\WecomConfig;
use App\Services\WecomService;
use Illuminate\Http\Request;

class WecomSettingsController extends Controller
{
    protected WecomService $wecom;

    public function __construct(WecomService $wecom)
    {
        $this->wecom = $wecom;
    }

    /**
     * 管理员 — 企业微信配置页
     */
    public function index()
    {
        $config   = WecomConfig::active();
        $logCount = \App\Models\WecomMessageLog::count();
        $stat     = [
            'bound'   => \App\Models\User::whereNotNull('wecom_userid')->count(),
            'total'   => \App\Models\User::count(),
            'logs'    => \App\Models\WecomMessageLog::orderByDesc('id')->limit(20)->get(),
        ];

        return view('admin.wecom.settings', compact('config', 'logCount', 'stat'));
    }

    /**
     * 管理员 — 保存配置
     */
    public function save(Request $request)
    {
        $validated = $request->validate([
            'corp_id'          => 'required|string|max:64',
            'agent_id'         => 'required|string|max:32',
            'secret'           => 'required|string|max:128',
            'token'            => 'nullable|string|max:64',
            'encoding_aes_key' => 'nullable|string|max:64',
        ]);

        // 停用旧配置
        WecomConfig::query()->update(['is_active' => false]);

        // 创建新配置
        WecomConfig::create([
            'corp_id'          => $validated['corp_id'],
            'agent_id'         => $validated['agent_id'],
            'secret'           => $validated['secret'],
            'token'            => $validated['token'] ?? '',
            'encoding_aes_key' => $validated['encoding_aes_key'] ?? '',
            'is_active'        => true,
        ]);

        // 清除缓存的 access_token
        cache()->forget('wecom_access_token_' . $validated['corp_id']);

        return back()->with('success', '企业微信配置已保存');
    }

    /**
     * 管理员 — 测试连接
     */
    public function testConnection()
    {
        try {
            $token  = $this->wecom->getAccessToken();
            $result = $this->wecom->sendText(
                '@all',
                '【产品研发管理系统】连接测试消息 — ' . now()->format('Y-m-d H:i:s')
            );

            if (($result['errcode'] ?? -1) === 0) {
                return back()->with('success', "连接成功！access_token: " . substr($token, 0, 12) . '...');
            }

            return back()->with('error', "API 返回错误: " . ($result['errmsg'] ?? '未知'));
        } catch (\Exception $e) {
            return back()->with('error', '连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 管理员 — 消息日志
     */
    public function messageLogs()
    {
        $logs = \App\Models\WecomMessageLog::with('user')
            ->orderByDesc('id')
            ->paginate(30);

        return view('admin.wecom.message-logs', compact('logs'));
    }
}
