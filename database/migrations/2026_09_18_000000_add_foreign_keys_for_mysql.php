<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MySQL 兼容性修复：外键补充迁移
 *
 * SQLite 允许外键引用尚未创建的表，因此原迁移按"先建 users/tasks/default_members，
 * 后建被引用表"的顺序也能运行。MySQL（InnoDB）则要求外键引用的表必须已存在，
 * 否则报错 SQLSTATE 1824 "Failed to open the referenced table"。
 *
 * 处理方式：在原始建表迁移中仅保留外键列 + 索引，不声明外键约束；
 * 待被引用表（departments/roles/task_types/project_templates）全部创建后，
 * 由本迁移统一补上外键约束，保证 MySQL 下的数据完整性。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('role_id')->references('id')->on('roles');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('task_type_id')->references('id')->on('task_types');
        });

        Schema::table('default_members', function (Blueprint $table) {
            $table->foreign('task_template_id')->references('id')->on('project_templates')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['task_type_id']);
        });

        Schema::table('default_members', function (Blueprint $table) {
            $table->dropForeign(['task_template_id']);
        });
    }
};
