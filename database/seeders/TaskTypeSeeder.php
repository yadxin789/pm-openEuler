<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TaskType;
use App\Models\Department;

class TaskTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 获取部门ID
        $deptIds = Department::pluck('id', 'code')->toArray();
        
        $taskTypes = [
            // 产品规划部
            ['name' => '立项书', 'description' => '项目立项相关文档', 'department_id' => $deptIds['product'] ?? null, 'sort_order' => 1],
            ['name' => '项目功能技术要求说明书', 'description' => '项目功能和技术要求说明', 'department_id' => $deptIds['product'] ?? null, 'sort_order' => 2],
            ['name' => '产品成本评估报告', 'description' => '产品成本评估分析报告', 'department_id' => $deptIds['product'] ?? null, 'sort_order' => 3],
            ['name' => '项目结案总结报告', 'description' => '项目结案总结文档', 'department_id' => $deptIds['product'] ?? null, 'sort_order' => 4],
            
            // 外观设计部
            ['name' => '产品外观设计图', 'description' => '产品外观设计图纸', 'department_id' => $deptIds['appearance'] ?? null, 'sort_order' => 5],
            ['name' => '外观设计说明书', 'description' => '外观设计说明文档', 'department_id' => $deptIds['appearance'] ?? null, 'sort_order' => 6],
            ['name' => '外观设计综合压缩包', 'description' => '外观设计相关文件压缩包', 'department_id' => $deptIds['appearance'] ?? null, 'sort_order' => 7],
            
            // 结构设计部
            ['name' => '产品结构图', 'description' => '产品结构设计图纸', 'department_id' => $deptIds['structure'] ?? null, 'sort_order' => 8],
            ['name' => '产品装配图', 'description' => '产品装配图纸', 'department_id' => $deptIds['structure'] ?? null, 'sort_order' => 9],
            ['name' => '产品结构说明书', 'description' => '产品结构说明文档', 'department_id' => $deptIds['structure'] ?? null, 'sort_order' => 10],
            ['name' => '结构BOM表', 'description' => '结构物料清单', 'department_id' => $deptIds['structure'] ?? null, 'sort_order' => 11],
            ['name' => '产品结构设计综合压缩包', 'description' => '结构设计相关文件压缩包', 'department_id' => $deptIds['structure'] ?? null, 'sort_order' => 12],
            
            // 硬件设计部
            ['name' => '电路原理图', 'description' => '电路原理图纸', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 13],
            ['name' => '原理图说明书', 'description' => '原理图说明文档', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 14],
            ['name' => '原理设计综合压缩包', 'description' => '原理设计相关文件压缩包', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 15],
            ['name' => 'PCB板图', 'description' => 'PCB板设计图纸', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 16],
            ['name' => 'PCB板3D图', 'description' => 'PCB板3D设计图', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 17],
            ['name' => '电子BOM表', 'description' => '电子物料清单', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 18],
            ['name' => 'PCB综合压缩包', 'description' => 'PCB设计相关文件压缩包', 'department_id' => $deptIds['hardware'] ?? null, 'sort_order' => 19],
            
            // 软件设计部
            ['name' => '软件流程图', 'description' => '软件流程设计图', 'department_id' => $deptIds['software'] ?? null, 'sort_order' => 20],
            ['name' => '软件设计说明书', 'description' => '软件设计说明文档', 'department_id' => $deptIds['software'] ?? null, 'sort_order' => 21],
            ['name' => '软件综合压缩包', 'description' => '软件设计相关文件压缩包', 'department_id' => $deptIds['software'] ?? null, 'sort_order' => 22],
            
            // 样品制作部
            ['name' => '样品制作要求书', 'description' => '样品制作要求说明', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 23],
            ['name' => '样品技术要求书', 'description' => '样品技术要求说明', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 24],
            ['name' => '样品测试项目要求书', 'description' => '样品测试项目要求', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 25],
            ['name' => '样本测试报告', 'description' => '样品测试报告文档', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 26],
            ['name' => '产品规格书', 'description' => '产品规格说明文档', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 27],
            ['name' => '样品制作过程总结报告', 'description' => '样品制作过程总结', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 28],
            ['name' => '样品制作综合压缩包', 'description' => '样品制作相关文件压缩包', 'department_id' => $deptIds['sample'] ?? null, 'sort_order' => 29],
            
            // 品质验证部
            ['name' => '品质验证项目要求书', 'description' => '品质验证项目要求', 'department_id' => $deptIds['quality'] ?? null, 'sort_order' => 30],
            ['name' => '品质测试数据表', 'description' => '品质测试数据记录', 'department_id' => $deptIds['quality'] ?? null, 'sort_order' => 31],
            ['name' => '品质验证报告', 'description' => '品质验证报告文档', 'department_id' => $deptIds['quality'] ?? null, 'sort_order' => 32],
            ['name' => '品质验证综合压缩包', 'description' => '品质验证相关文件压缩包', 'department_id' => $deptIds['quality'] ?? null, 'sort_order' => 33],
            
            // 包装设计部
            ['name' => '包装设计图', 'description' => '包装设计图纸', 'department_id' => $deptIds['packaging'] ?? null, 'sort_order' => 34],
            ['name' => '标示标签徽标设计图', 'description' => '标签徽标设计图纸', 'department_id' => $deptIds['packaging'] ?? null, 'sort_order' => 35],
            ['name' => '包装设计说明书', 'description' => '包装设计说明文档', 'department_id' => $deptIds['packaging'] ?? null, 'sort_order' => 36],
            ['name' => '包装设计综合压缩包', 'description' => '包装设计相关文件压缩包', 'department_id' => $deptIds['packaging'] ?? null, 'sort_order' => 37],
        ];

        foreach ($taskTypes as $taskType) {
            TaskType::create(array_merge($taskType, ['is_required' => 1]));
        }
    }
}