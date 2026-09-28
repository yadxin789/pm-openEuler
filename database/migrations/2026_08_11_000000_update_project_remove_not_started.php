<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 将所有未启动状态的项目转为进行中状态
        DB::table('projects')
            ->where('status', 'App\\States\\Project\\NotStarted')
            ->update([
                'status' => 'App\\States\\Project\\InProgress',
            ]);

        // 对于没有 start_date 的项目，设置为当前日期
        DB::table('projects')
            ->whereNull('start_date')
            ->update([
                'start_date' => now()->toDateString(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚：不做处理，因为 NotStarted 状态已被移除
    }
};
