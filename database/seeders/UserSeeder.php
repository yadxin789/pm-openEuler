<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        $adminRole = Role::where('code', 'admin')->first();
        $creatorRole = Role::where('code', 'project_creator')->first();
        $projectManagerRole = Role::where('code', 'project_manager')->first();
        $executorRole = Role::where('code', 'task_executor')->first();
        $auditorRole = Role::where('code', 'task_auditor')->first();
        $supportRole = Role::where('code', 'support')->first();

        $adminDept = Department::where('code', 'admin_dept')->first();

        // 管理员
        User::create([
            'username' => 'admin',
            'password' => Hash::make('123456'),
            'name' => '系统管理员',
            'department_id' => $adminDept->id,
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        // 项目监督人（原项目管理部总经理，管理所有项目）
        $pmDept = Department::where('code', 'pm')->first();
        if ($pmDept && $projectManagerRole) {
            User::create([
                'username' => 'pm_director',
                'password' => Hash::make('123456'),
                'name' => '项目监督人',
                'department_id' => $pmDept->id,
                'role_id' => $projectManagerRole->id,
                'is_active' => true,
            ]);
        }

        // 项目参与部门 - 每个部门创建用户
        $projectDepts = Department::where('type', 'project')->get();
        
        foreach ($projectDepts as $dept) {
            if ($dept->code === 'pm') {
                // 项目管理部 - 项目创建人（原项目经理）
                for ($i = 1; $i <= 2; $i++) {
                    User::create([
                        'username' => 'pm_manager_' . $i,
                        'password' => Hash::make('123456'),
                        'name' => "项目管理部项目创建人{$i}",
                        'department_id' => $dept->id,
                        'role_id' => $creatorRole->id,
                        'is_active' => true,
                    ]);
                }
            } else {
                // 其他部门 - 任务审核人（原主管）
                for ($i = 1; $i <= 2; $i++) {
                    User::create([
                        'username' => $dept->code . '_manager_' . $i,
                        'password' => Hash::make('123456'),
                        'name' => "{$dept->name}任务审核人{$i}",
                        'department_id' => $dept->id,
                        'role_id' => $auditorRole->id,
                        'is_active' => true,
                    ]);
                }

                // 任务执行人（原设计师）
                for ($i = 1; $i <= 2; $i++) {
                    User::create([
                        'username' => $dept->code . '_designer_' . $i,
                        'password' => Hash::make('123456'),
                        'name' => "{$dept->name}任务执行人{$i}",
                        'department_id' => $dept->id,
                        'role_id' => $executorRole->id,
                        'is_active' => true,
                    ]);
                }

                // 支援人员（原文员）
                for ($i = 1; $i <= 2; $i++) {
                    User::create([
                        'username' => $dept->code . '_clerk_' . $i,
                        'password' => Hash::make('123456'),
                        'name' => "{$dept->name}支援人员{$i}",
                        'department_id' => $dept->id,
                        'role_id' => $supportRole->id,
                        'is_active' => true,
                    ]);
                }
            }
        }

        // 非项目参与部门 - 支援人员（原文员）
        $nonProjectDepts = Department::where('type', 'non-project')->where('code', '!=', 'admin_dept')->get();
        foreach ($nonProjectDepts as $dept) {
            for ($i = 1; $i <= 2; $i++) {
                User::create([
                    'username' => $dept->code . '_clerk_' . $i,
                    'password' => Hash::make('123456'),
                    'name' => "{$dept->name}支援人员{$i}",
                    'department_id' => $dept->id,
                    'role_id' => $supportRole->id,
                    'is_active' => true,
                ]);
            }
        }
    }
}