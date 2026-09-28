<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wecom_configs', function (Blueprint $table) {
            $table->id();
            $table->string('corp_id', 64)->comment('企业ID');
            $table->string('agent_id', 32)->comment('应用AgentId');
            $table->string('secret', 128)->comment('应用Secret');
            $table->string('token', 64)->comment('回调Token');
            $table->string('encoding_aes_key', 64)->comment('回调EncodingAESKey');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wecom_configs');
    }
};
