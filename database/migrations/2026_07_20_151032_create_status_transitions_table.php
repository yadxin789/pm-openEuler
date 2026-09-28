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
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');              // 'project' 或 'task'
            $table->unsignedBigInteger('model_id');     // 项目或任务ID
            $table->string('from_status');              // 原状态
            $table->string('to_status');                // 新状态
            $table->unsignedBigInteger('user_id')->nullable(); // 操作人
            $table->text('note')->nullable();           // 备注
            $table->timestamps();
            
            $table->index(['model_type', 'model_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_transitions');
    }
};
