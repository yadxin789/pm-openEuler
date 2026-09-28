<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WecomOauthState;
use App\Services\WecomService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WecomAuthController extends Controller
{
    protected WecomService $wecom;

    public function __construct(WecomService $wecom)
    {
        $this->wecom = $wecom;
    }

    /**
     * 发起 OAuth 授权 — 跳转到企业微信授权页
     */
    public function redirect(Request $request)
    {
        if (!$this->wecom->isEnabled()) {
            $msg = auth()->user()?->isAdmin()
                ? '企业微信尚未配置，请先前往「管理后台 → 企业微信」完成系统配置'
                : '企业微信尚未配置，请联系系统管理员';
            return back()->with('error', $msg);
        }

        // 生成 anti-CSRF state
        $state       = Str::random(40);
        $redirectUrl = $request->input('redirect', route('profile.wecom.index'));

        WecomOauthState::create([
            'state'        => $state,
            'redirect_url' => $redirectUrl,
            'expires_at'   => now()->addSeconds(config('wecom.oauth.state_timeout', 300)),
        ]);

        $authUrl = $this->wecom->buildOAuthUrl(route('wecom.callback'), $state);

        return redirect()->away($authUrl);
    }

    /**
     * OAuth 回调 — 完成用户绑定
     */
    public function callback(Request $request)
    {
        $code  = $request->input('code');
        $state = $request->input('state');

        if (!$code || !$state) {
            return redirect()->route('profile.wecom.index')->with('error', '授权回调参数缺失');
        }

        // 校验 state
        $oauthState = WecomOauthState::valid($state);
        if (!$oauthState) {
            return redirect()->route('profile.wecom.index')->with('error', '授权已过期，请重试');
        }

        // 删除已使用的 state
        WecomOauthState::where('state', $state)->delete();

        // 通过 code 换取企业微信用户身份
        $result = $this->wecom->getUserInfoByCode($code);

        if (($result['errcode'] ?? -1) !== 0) {
            return redirect()->route('profile.wecom.index')->with(
                'error',
                '获取企业微信身份失败: ' . ($result['errmsg'] ?? '未知错误')
            );
        }

        $wecomUserId = $result['userid'] ?? ($result['UserId'] ?? '');

        if (empty($wecomUserId)) {
            return redirect()->route('profile.wecom.index')->with('error', '未能获取到企业微信用户ID');
        }

        // 检查该企业微信账号是否已被其他用户绑定
        $existing = User::where('wecom_userid', $wecomUserId)
            ->where('id', '!=', auth()->id())
            ->first();

        if ($existing) {
            return redirect()->route('profile.wecom.index')->with(
                'error',
                "该企业微信账号已被用户「{$existing->name}」绑定"
            );
        }

        // 执行绑定
        $user = auth()->user();
        $user->wecom_userid  = $wecomUserId;
        $user->wecom_bound_at = now();
        $user->wecom_notify_enabled = true; // 默认开启通知
        $user->save();

        return redirect($oauthState->redirect_url ?? route('profile.wecom.index'))
            ->with('success', '企业微信绑定成功！');
    }
}
