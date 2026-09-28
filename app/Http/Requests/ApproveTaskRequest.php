<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->id === $this->route('task')->auditor_id;
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => '审核意见不能超过500个字符',
        ];
    }
}
