<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_room_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('started_by')->comment('发起人=host');
            $table->foreignId('collab_document_id')->nullable()->comment('当前演示文稿')->constrained('collab_documents')->nullOnDelete();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('started_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['voice_room_id', 'started_at'], 'voice_sessions_room_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
    }
};
