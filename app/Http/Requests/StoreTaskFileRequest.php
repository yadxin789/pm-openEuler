<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->id === $this->route('task')->executor_id;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => '请选择要上传的文件',
            'file.file' => '上传内容必须是文件',
        ];
    }

    public function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        // 自定义验证失败处理，提供更详细的错误提示
        $errors = $validator->errors();
        
        if ($errors->has('file')) {
            $file = $this->file('file');
            if ($file) {
                $fileSize = $file->getSize();
                $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                $fileName = $file->getClientOriginalName();
                
                // 检查是否是文件大小限制
                if ($errors->first('file') === 'validation.max.file') {
                    $maxSize = config('pm.max_upload_size', 20480);
                    $maxSizeMB = round($maxSize / 1024, 2);
                    $errors->add('file', 
                        "文件上传失败：\n" .
                        "文件名：{$fileName}\n" .
                        "文件大小：{$fileSizeMB} MB\n" .
                        "系统限制：{$maxSizeMB} MB\n\n" .
                        "请联系管理员调整上传大小限制。"
                    );
                }
            }
        }
        
        throw new \Illuminate\Validation\ValidationException($validator);
    }
}