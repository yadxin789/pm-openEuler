<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $rules = [
            'username' => 'required|string|max:50|unique:users,username' . ($this->user ? ',' . $this->user->id : ''),
            'name' => 'required|string|max:50',
            'department_id' => 'required|integer|exists:departments,id',
            'role_id' => 'required|integer|exists:roles,id',
        ];

        // 创建时需要密码，更新时可选
        if ($this->isMethod('post')) {
            $rules['password'] = ['required', 'confirmed', Password::min(6)->letters()->numbers()];
        } elseif ($this->filled('password')) {
            $rules['password'] = ['required', 'confirmed', Password::min(6)->letters()->numbers()];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'username.required' => '请输入用户名',
            'username.unique' => '该用户名已被使用',
            'name.required' => '请输入姓名',
            'department_id.required' => '请选择所属部门',
            'department_id.exists' => '所选部门不存在',
            'role_id.required' => '请选择角色',
            'role_id.exists' => '所选角色不存在',
            'password.required' => '请输入密码',
            'password.confirmed' => '两次输入的密码不一致',
            'password.min' => '密码长度不能少于6位',
        ];
    }
}
