<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('project_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('action');
            $table->text('content');
            $table->timestamps();
            
            // 性能索引
            $table->index('project_id', 'plog_project_idx');
            $table->index('user_id', 'plog_user_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('project_logs');
    }
};