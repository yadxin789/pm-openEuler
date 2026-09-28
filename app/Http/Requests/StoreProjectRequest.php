<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isProjectCreator() || $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:projects,code',
            'version' => 'required|string|max:20',
            'product_name' => 'required|string|max:255',
            'product_model' => 'required|string|max:255',
            'start_date' => 'required|date|after_or_equal:today',
            'selected_depts' => 'required|array|min:1',
            'selected_depts.*' => 'integer|exists:departments,id',
            'dept_days' => 'required|array',
            'dept_days.*' => 'integer|min:1|max:365',
            'tasks' => 'required|array|min:1',
            'tasks.*.task_type_id' => 'required|integer|exists:task_types,id',
            'tasks.*.department_id' => 'required|integer|exists:departments,id',
            'tasks.*.executor_id' => 'nullable|integer|exists:users,id',
            'tasks.*.auditor_id' => 'nullable|integer|exists:users,id',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // 只验证选中部门且启用的任务
            $selectedDepts = $this->input('selected_depts', []);
            $tasks = $this->input('tasks', []);

            foreach ($tasks as $index => $task) {
                // 跳过未选中部门的任务
                if (!in_array($task['department_id'], $selectedDepts)) {
                    continue;
                }
                
                // 跳过未启用的任务
                if (!isset($task['enabled']) || $task['enabled'] != '1') {
                    continue;
                }

                if (empty($task['executor_id'])) {
                    $validator->errors()->add("tasks.{$index}.executor_id", "任务 {$index} 需要选择执行人");
                }
                if (empty($task['auditor_id'])) {
                    $validator->errors()->add("tasks.{$index}.auditor_id", "任务 {$index} 需要选择审核人");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入项目名称',
            'version.required' => '请输入项目版本号',
            'product_name.required' => '请输入产品名称',
            'product_model.required' => '请输入产品型号',
            'start_date.required' => '请选择开始日期',
            'start_date.after_or_equal' => '开始日期不能早于今天',
            'selected_depts.required' => '请至少选择一个部门',
            'dept_days.*.min' => '部门天数不能少于1天',
            'dept_days.*.max' => '部门天数不能超过365天',
            'tasks.required' => '请至少配置一个任务',
        ];
    }
}