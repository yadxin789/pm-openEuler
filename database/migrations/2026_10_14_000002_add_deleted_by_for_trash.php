<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为纳入回收站的表补充「删除人」列 deleted_by。
 *
 * 用途（个人回收站）：
 * - 与各模型的「归属人列」一起决定个人回收站的可见范围：
 *   我删除的（deleted_by = 我） 或 我创建的（归属人 = 我）。
 *   这样管理员删除的内容，创建人依然能恢复。
 *
 * 约定：
 * - 软删除时由模型事件自动写入 deleted_by（见 AppServiceProvider::boot）；
 * - 恢复时由 TrashService::restore 清空；
 * - 历史数据为 NULL，不影响按「归属人」匹配。
 */
return new class extends Migration
{
    /**
     * 需要补 deleted_by 的表（与回收站分类一一对应）。
     */
    protected array $tables = [
        'collab_documents',   // 协作文档
        'projects',           // 项目
        'tasks',              // 任务
        'files',              // 任务文件
        'department_files',   // 资料文件
        'messages',           // 聊天记录
        'wecom_files',        // 企微文件
        'notifications',      // 通知
        'knowledges',         // 知识库
        'knowledge_docs',     // 知识文档
        'project_templates',  // 项目模板
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_by')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at')->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'deleted_by')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('deleted_by');
            });
        }
    }
};
