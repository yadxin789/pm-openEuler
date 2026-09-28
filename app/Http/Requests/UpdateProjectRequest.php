<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        return $this->user()->id === $project->creator_id || $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:projects,code,' . $this->route('project')->id,
            'version' => 'required|string|max:20',
            'product_name' => 'required|string|max:255',
            'product_model' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入项目名称',
            'code.required' => '请输入项目编码',
            'code.unique' => '该项目编码已被使用',
            'version.required' => '请输入项目版本号',
            'product_name.required' => '请输入产品名称',
            'product_model.required' => '请输入产品型号',
        ];
    }
}
