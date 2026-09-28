<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentFile extends Model
{
    protected $fillable = [
        'department_id', 'uploader_id', 'name', 'original_name', 'path', 'size', 'mime_type', 'category', 'visibility', 'version'
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function getFormattedSizeAttribute()
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * 资料文件分组类型选项。
     */
    public static function categoryOptions(): array
    {
        return [
            'template' => '模板文件',
            'example' => '范例文件',
            'product_spec' => '产品规格书',
            'component_spec' => '元器件规格书',
            'material' => '物料文件',
            'safety' => '安规标准文件',
            'certification' => '认证文件',
            'patent' => '专利文件',
        ];
    }

    /**
     * 资料文件权限范围选项。
     */
    public static function visibilityOptions(): array
    {
        return [
            'all' => '全部用户可见',
            'department' => '本部门用户可见',
            'private' => '仅本人可见',
        ];
    }

    /**
     * 获取文件分类名称
     */
    public function getCategoryNameAttribute()
    {
        return self::categoryOptions()[$this->category] ?? '未知';
    }

    /**
     * 获取权限范围名称。
     */
    public function getVisibilityNameAttribute()
    {
        return self::visibilityOptions()[$this->visibility] ?? '未知';
    }

    /**
     * 判断文件是否支持预览
     * 所有文件都显示预览按钮，不支持的格式在预览时会弹出提示
     */
    public function isPreviewable()
    {
        return true;
    }

    /**
     * 检查用户是否有权限预览/下载
     */
    public function canAccess($userId)
    {
        $user = \App\Models\User::find($userId);
        
        if (!$user) {
            return false;
        }

        // 按上传时选择的权限范围控制资料文件访问。
        if ($this->visibility === 'all') {
            return true;
        }
        
        if ($this->visibility === 'department') {
            return $user->department_id === $this->department_id;
        }

        return $this->visibility === 'private' && $this->uploader_id === $user->id;
    }
}