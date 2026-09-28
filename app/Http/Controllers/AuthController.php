<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use App\Models\Role;
use App\Helpers\OperationLogHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use App\Http\Requests\LoginRequest;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request)
    {
        $throttleKey = 'login:' . $request->username . '|' . $request->ip();
        $maxAttempts = config('pm.login_max_attempts', 5);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors([
                'username' => "登录尝试次数过多，请在 {$seconds} 秒后重试",
            ]);
        }

        if (Auth::attempt(['username' => $request->username, 'password' => $request->password])) {
            RateLimiter::clear($throttleKey);
            
            $user = Auth::user();
            
            // 检查账号是否已被激活
            if (!$user->is_active) {
                Auth::logout();
                return back()->withErrors([
                    'username' => '您的账号尚未激活，请联系管理员',
                ])->onlyInput('username');
            }
            
            $request->session()->regenerate();
            OperationLogHelper::log('认证', '登录', "用户「{$user->name}」登录系统");
            
            $this->refreshProjectStatusOnLogin();
            
            return redirect()->route('dashboard');
        }

        RateLimiter::hit($throttleKey, config('pm.login_decay_minutes', 15) * 60);
        OperationLogHelper::log('认证', '登录失败', "用户名「{$request->username}」登录失败");
        
        return back()->withErrors([
            'username' => '用户名或密码错误',
        ]);
    }

    public function showRegisterForm()
    {
        $departments = Department::all();
        $roles = Role::all();
        return view('auth.register', compact('departments', 'roles'));
    }

    public function register(Request $request)
    {
        $request->validate([
            'username' => 'required|string|max:50|unique:users',
            'password' => ['required', 'confirmed', Password::min(6)->letters()->numbers()],
            'name' => 'required|string|max:50',
            'department_id' => 'required|integer|exists:departments,id',
            'role_id' => 'required|integer|exists:roles,id',
        ], [
            'department_id.exists' => '所选部门不存在',
            'role_id.exists' => '所选角色不存在',
            'password.min' => '密码长度不能少于6位',
        ]);

        $user = User::create([
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'name' => $request->name,
            'department_id' => $request->department_id,
            'role_id' => $request->role_id,
            // 注册后默认禁用，需要管理员激活后才可以登录
            'is_active' => false,
        ]);

        OperationLogHelper::log('认证', '注册', "新用户「{$user->name}」注册成功（待管理员激活）", $user->id);

        return redirect()->route('login')->with('success', '注册成功，请等待管理员激活账号后登录');
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        $userName = $user ? $user->name : '未知用户';
        $userId = $user ? $user->id : null;
        
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        // 记录登出操作日志
        OperationLogHelper::log('认证', '退出', "用户「{$userName}」退出登录", $userId);
        
        return redirect('/');
    }
    
    /**
     * 用户登录时刷新项目状态和进度
     */
    private function refreshProjectStatusOnLogin()
    {
        try {
            // 获取用户参与的项目
            $user = Auth::user();
            $userProjectIds = \App\Models\Project::whereHas('tasks', function($query) use ($user) {
                $query->where('executor_id', $user->id)
                      ->orWhere('auditor_id', $user->id);
            })->orWhere('creator_id', $user->id)->pluck('id');
            
            // 只刷新用户相关的项目
            foreach ($userProjectIds as $projectId) {
                $project = \App\Models\Project::find($projectId);
                if ($project) {
                    $project->calculateProgress();
                }
            }
        } catch (\Exception $e) {
            // 静默失败，不影响登录流程
            \Log::warning('登录时刷新项目状态失败: ' . $e->getMessage());
        }
    }
}