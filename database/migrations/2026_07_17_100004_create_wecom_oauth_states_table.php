<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wecom_oauth_states', function (Blueprint $table) {
            $table->id();
            $table->string('state', 128)->unique()->comment('OAuth state值');
            $table->string('redirect_url', 255)->nullable()->comment('绑定后跳转URL');
            $table->timestamp('expires_at')->comment('过期时间');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wecom_oauth_states');
    }
};
