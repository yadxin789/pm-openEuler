<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_session_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('user_id');
            $table->dateTimeTz('joined_at');
            $table->dateTimeTz('left_at')->nullable();
            $table->boolean('was_presenter')->default(false)->comment('是否在本次会议中担任过演示者');
            $table->string('leave_reason', 30)->nullable()->comment('normal/timeout/kicked');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['voice_session_id', 'user_id'], 'voice_participants_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_participants');
    }
};
