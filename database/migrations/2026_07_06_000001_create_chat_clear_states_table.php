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
        Schema::create('chat_clear_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('chat_type', 20);
            $table->foreignId('other_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('conversation_key', 64);
            $table->timestamp('cleared_at');
            $table->timestamps();

            // 每个用户在每个项目的群聊或指定私聊会话只保留一条清空状态。
            $table->unique(['user_id', 'project_id', 'chat_type', 'conversation_key'], 'chat_clear_unique_conversation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_clear_states');
    }
};
