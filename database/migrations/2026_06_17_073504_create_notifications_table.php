<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('sender_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('type');
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            // 性能索引
            $table->index(['user_id', 'is_read'], 'notifications_user_read_idx');
            $table->index(['user_id', 'created_at'], 'notifications_user_created_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notifications');
    }
};