<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI 聊天：多会话 / 消息 / 附件
     * - 替代原来「只存在 Session 里的单条对话历史」，支持查看、切换、删除、新建会话
     */
    public function up(): void
    {
        // 会话
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200)->default('新会话');
            $table->string('skill_slug', 100)->nullable();   // 当前会话绑定的技能（可为空＝默认全能力）
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'last_message_at']);
        });

        // 消息
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system'])->default('user');
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();               // 技能、模型、用量等附加信息
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        // 附件（图片 / 文件）
        Schema::create('ai_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['image', 'file'])->default('file');
            $table->string('original_name', 300);
            $table->string('path', 500);                    // 相对 ai_chat 磁盘根目录
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('text_extracted')->default(false); // 文本内容是否已作为上下文送入 AI
            $table->longText('extracted_text')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachments');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
