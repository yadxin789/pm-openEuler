<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            // 每用户限流（管理员可调）：每分钟次数 / 每日次数
            $table->unsignedInteger('rate_limit_minute')->default(30)->after('max_tokens');
            $table->unsignedInteger('rate_limit_daily')->default(200)->after('rate_limit_minute');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['rate_limit_minute', 'rate_limit_daily']);
        });
    }
};
