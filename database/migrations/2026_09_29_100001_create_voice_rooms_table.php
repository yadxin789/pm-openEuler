<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->comment('一个项目对应一个会议房')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('host_id')->comment('主持人=项目创建人，冗余自 projects.creator_id');
            $table->unsignedBigInteger('current_presenter_id')->nullable()->comment('当前演示者 user_id，初始=host');
            $table->unsignedBigInteger('janus_room_id')->nullable()->comment('Janus AudioBridge room id');
            $table->boolean('active')->default(false)->comment('会议是否进行中');
            $table->timestamps();

            $table->foreign('host_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('current_presenter_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_rooms');
    }
};
