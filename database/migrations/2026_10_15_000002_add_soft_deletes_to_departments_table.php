<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 部门支持软删除。
 *
 * 背景：原 departments 表无 deleted_at 列、Department 模型未使用 SoftDeletes trait，
 * 导致「删部门」实际上是物理删除，进而会触发 users.department_id 的 RESTRICT 外键冲突。
 *
 * 软删部门的业务价值：
 *   1. 避开外键冲突，部门可正常删除；
 *   2. 部门成员转为普通用户（department_id 置空）后仍能保留原身份访问历史项目；
 *   3. 部门本身进回收站 15 天流程，超期后由 trash:purge-expired 一并清理。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at', 'departments_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex('departments_deleted_idx');
            $table->dropSoftDeletes();
        });
    }
};
