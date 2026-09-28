<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('uploader_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('auditor_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->bigInteger('size');
            $table->string('mime_type');
            $table->integer('version')->default(1);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('audit_time')->nullable();
            $table->text('audit_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // 性能索引
            $table->index(['task_id', 'status'], 'files_task_status_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('files');
    }
};