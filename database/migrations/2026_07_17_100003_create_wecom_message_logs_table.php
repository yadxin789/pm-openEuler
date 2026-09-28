<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wecom_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->comment('接收用户');
            $table->string('msg_type', 32)->default('textcard')->comment('消息类型');
            $table->string('title')->nullable()->comment('消息标题');
            $table->text('content')->nullable()->comment('消息内容');
            $table->string('response_code', 16)->nullable()->comment('API响应码');
            $table->text('response_body')->nullable()->comment('API响应体');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wecom_message_logs');
    }
};
