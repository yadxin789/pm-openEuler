<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * 基本资料页面（参考勾股OA edit_personal 布局）
     */
    public function editPersonal()
    {
        return view('profile.edit', ['user' => auth()->user()]);
    }

    /**
     * 保存基本资料
     */
    public function updatePersonal(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login')->with('error', '请先登录');
        }

        $request->validate([
            'name' => 'required|string|max:50',
            'bio' => 'nullable|string|max:500',
        ]);

        $user->name = trim($request->name);
        $user->bio = trim($request->bio ?? '');
        $user->save();

        return redirect()->route('profile.edit')->with('success', '基本资料保存成功！');
    }

    /**
     * 修改密码页面（参考勾股OA edit_password 布局）
     */
    public function editPassword()
    {
        return view('profile.password', ['user' => auth()->user()]);
    }

    /**
     * 保存新密码，成功后退出登录，使用新密码重新登录
     */
    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login')->with('error', '请先登录');
        }

        $request->validate([
            'old_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'old_password.required' => '请输入旧密码',
            'password.required' => '请输入新密码',
            'password.min' => '新密码长度不能少于 6 位',
            'password.confirmed' => '两次输入的新密码不一致',
        ]);

        if (!Hash::check($request->old_password, $user->password)) {
            throw ValidationException::withMessages([
                'old_password' => '旧密码错误',
            ]);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // 参考勾股OA：修改成功后注销当前会话，提示使用新密码登录
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', '密码修改成功，请使用新密码登录');
    }

    public function avatar()
    {
        return view('profile.avatar');
    }

    public function uploadAvatar(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login')->with('error', '请先登录');
        }

        // 检查是否选择了默认头像
        if ($request->has('default_avatar')) {
            $fileName = $request->default_avatar;
        } else {
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            // 删除旧头像（如果不是默认头像）
            if ($user->avatar && !str_starts_with($user->avatar, 'defaults/')) {
                if (Storage::disk('public')->exists('avatars/' . $user->avatar)) {
                    Storage::disk('public')->delete('avatars/' . $user->avatar);
                }
            }

            // 上传新头像到 public 磁盘
            $fileName = time() . '_' . $request->avatar->getClientOriginalName();
            $request->avatar->storeAs('avatars', $fileName, 'public');
        }

        // 更新用户头像
        $user->avatar = $fileName;
        $user->save();

        return redirect()->route('profile.avatar')->with('success', '头像上传成功！');
    }
}
