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
        Schema::create('wecom_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('发送文件的企微用户');
            $table->string('media_id', 128)->comment('企微临时素材 ID（3天有效）');
            $table->string('msg_type', 20)->default('file')->comment('消息类型: image/file/voice/video');
            $table->string('original_name', 512)->nullable()->comment('原始文件名（从回调解析）');
            $table->string('stored_name')->comment('系统存储文件名');
            $table->string('file_path')->comment('相对于 wecom_files 磁盘的路径');
            $table->unsignedBigInteger('file_size')->default(0)->comment('文件大小（字节）');
            $table->string('mime_type', 128)->nullable()->comment('MIME 类型');
            $table->string('status', 20)->default('pending')->comment('处理状态: pending/archived/linked');
            $table->string('remark')->nullable()->comment('备注');
            $table->nullableMorphs('related');  // 关联到任务或项目（nullableMorphs 支持空值）
            $table->json('callback_data')->nullable()->comment('原始回调数据');
            $table->timestamps();
            $table->index('status');
            $table->index('media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wecom_files');
    }
};
