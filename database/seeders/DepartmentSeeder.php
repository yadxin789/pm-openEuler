<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run()
    {
        $departments = [
            ['name' => '项目管理部', 'code' => 'pm', 'type' => 'project', 'sort_order' => 1],
            ['name' => '产品规划部', 'code' => 'product', 'type' => 'project', 'sort_order' => 2],
            ['name' => '外观设计部', 'code' => 'appearance', 'type' => 'project', 'sort_order' => 3],
            ['name' => '结构设计部', 'code' => 'structure', 'type' => 'project', 'sort_order' => 4],
            ['name' => '硬件设计部', 'code' => 'hardware', 'type' => 'project', 'sort_order' => 5],
            ['name' => '软件设计部', 'code' => 'software', 'type' => 'project', 'sort_order' => 6],
            ['name' => '样品制作部', 'code' => 'sample', 'type' => 'project', 'sort_order' => 7],
            ['name' => '品质验证部', 'code' => 'quality', 'type' => 'project', 'sort_order' => 8],
            ['name' => '包装设计部', 'code' => 'packaging', 'type' => 'project', 'sort_order' => 9],
            ['name' => '系统后台管理部', 'code' => 'admin_dept', 'type' => 'non-project', 'sort_order' => 10],
            ['name' => '物料管理部', 'code' => 'material', 'type' => 'non-project', 'sort_order' => 11],
            ['name' => '专利管理部', 'code' => 'patent', 'type' => 'non-project', 'sort_order' => 12],
            ['name' => '认证管理部', 'code' => 'certification', 'type' => 'non-project', 'sort_order' => 13],
        ];

        foreach ($departments as $dept) {
            Department::create($dept);
        }
    }
}