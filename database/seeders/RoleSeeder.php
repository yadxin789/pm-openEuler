<?php

namespace Database\Seeders;

use App\Models\Role;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['name' => '系统管理员', 'code' => 'admin', 'description' => '系统后台管理权限'],
            ['name' => '项目创建人', 'code' => 'project_creator', 'description' => '创建和管理项目（原项目经理岗位）'],
            ['name' => '项目监督人', 'code' => 'project_manager', 'description' => '管理所有项目，查看全部项目数据（原项目管理部总经理岗位）'],
            ['name' => '任务执行人', 'code' => 'task_executor', 'description' => '执行任务并上传文件（原设计师岗位）'],
            ['name' => '任务审核人', 'code' => 'task_auditor', 'description' => '审核任务文件（原主管岗位）'],
            ['name' => '支援人员', 'code' => 'support', 'description' => '部门资料支持（原文员岗位）'],
        ];

        foreach ($roles as $role) {
            // 创建自定义 roles 表记录
            Role::firstOrCreate(
                ['code' => $role['code']],
                $role
            );

            // 同步创建 Spatie 权限角色
            SpatieRole::firstOrCreate(
                ['name' => $role['code'], 'guard_name' => 'web'],
                ['name' => $role['code'], 'guard_name' => 'web']
            );
        }
    }
}
