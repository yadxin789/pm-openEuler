<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('任务类型名称');
            $table->string('description')->nullable()->comment('任务类型描述');
            $table->unsignedBigInteger('department_id')->nullable()->comment('关联部门');
            $table->tinyInteger('is_required')->default(0)->comment('是否必需');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->timestamps();

            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_types');
    }
};