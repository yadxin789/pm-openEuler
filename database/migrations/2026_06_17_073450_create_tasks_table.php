<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('department_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->foreignId('executor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('auditor_id')->constrained('users')->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            // 使用 Spatie Model States，存储状态类名（如 App\States\Task\Pending）
            $table->string('status')->default(\App\States\Task\Pending::class);
            $table->foreignId('task_type_id')->nullable()->index();
            $table->boolean('is_overdue')->default(false);
            $table->integer('extension_days')->default(0);
            $table->dateTime('paused_at')->nullable()->comment('暂停时间');
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            
            // 性能索引
            $table->index(['project_id', 'status'], 'tasks_project_status_idx');
            $table->index(['executor_id', 'status'], 'tasks_executor_status_idx');
            $table->index(['auditor_id', 'status'], 'tasks_auditor_status_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tasks');
    }
};