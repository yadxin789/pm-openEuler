<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 模板任务配置表
        Schema::create('template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_template_id')->constrained()->onDelete('cascade')->comment('所属项目模板');
            $table->foreignId('task_type_id')->constrained()->comment('任务类型');
            $table->foreignId('executor_id')->nullable()->constrained('users')->onDelete('cascade')->comment('执行人');
            $table->foreignId('auditor_id')->nullable()->constrained('users')->onDelete('cascade')->comment('审核人');
            $table->foreignId('department_id')->nullable()->constrained()->comment('所属部门');
            $table->integer('days')->default(5)->comment('部门天数');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_tasks');
    }
};