<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('version')->default('1.0');
            $table->string('product_name');
            $table->string('product_model');
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            // 使用 Spatie Model States，存储状态类名（如 App\States\Project\NotStarted）
            $table->string('status')->default(\App\States\Project\NotStarted::class);
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('paused_reason', 500)->nullable()->comment('暂停原因');
            $table->dateTime('paused_at')->nullable()->comment('暂停时间');
            $table->dateTime('resumed_at')->nullable()->comment('恢复时间');
            $table->integer('total_paused_days')->default(0)->comment('累计暂停天数');
            $table->timestamps();
            $table->softDeletes();
            
            // 性能索引
            $table->index('creator_id', 'projects_creator_idx');
            $table->index('created_at', 'projects_created_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('projects');
    }
};