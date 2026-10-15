<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 任务文件历史版本快照改造。
 *
 * 原设计的问题：file_versions.path 记录的是与 files.path **完全相同**的路径（不复制文件），
 * 并且 file_id 外键为 CASCADE。由此产生两个后果：
 *   1. 旧版本文件被彻底删除后，版本记录指向一个不存在的文件（记录与文件不匹配）；
 *   2. 版本记录随 files 记录的物理删除而一起消失，历史版本根本无法追溯。
 *
 * 改造后：
 *   - path 指向**独立的快照文件**（{任务文件目录}/history/{fileId}_v{n}.{ext}），
 *     旧文件被删除后快照仍可追溯；
 *   - task_id（CASCADE）：快照归属任务，任务被彻底删除时快照与记录一并清理，不会成为孤儿；
 *   - file_id 改为可空 + SET NULL：原文件记录消失后，版本记录依然保留（file_id 置空）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_versions', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
        });

        Schema::table('file_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('file_id')->nullable()->change();
            $table->foreignId('task_id')->nullable()->after('file_id')->constrained('tasks')->onDelete('cascade');
            $table->string('path')->nullable()->change();
            $table->foreign('file_id')->references('id')->on('files')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('file_versions', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
            $table->dropForeign(['task_id']);
        });

        Schema::table('file_versions', function (Blueprint $table) {
            $table->dropColumn('task_id');
        });

        Schema::table('file_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('file_id')->nullable(false)->change();
            $table->string('path')->nullable(false)->change();
            $table->foreign('file_id')->references('id')->on('files')->onDelete('cascade');
        });
    }
};
