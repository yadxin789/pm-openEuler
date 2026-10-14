<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为纳入"管理员回收站"的表补充 deleted_at 列。
 *
 * 说明：
 * - users / projects / tasks / files / messages / collab_documents 建表时已有 deleted_at，此处不重复添加；
 * - 下列表原本是"删除即物理删除"，改为软删除后可进入 /admin/collab-trash 回收站，由管理员恢复或彻底删除。
 */
return new class extends Migration
{
    /**
     * 需要补 softDeletes 的表。
     */
    protected array $tables = [
        'department_files',   // 资料文件
        'wecom_files',        // 企微文件
        'notifications',      // 通知中心
        'knowledges',         // 知识库
        'knowledge_docs',     // 知识文档
        'project_templates',  // 项目模板
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
