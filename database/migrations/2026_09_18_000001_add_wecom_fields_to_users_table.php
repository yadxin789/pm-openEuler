<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 补充 users 表的企业微信绑定/通知字段。
     * 说明：代码（User 模型 fillable、WecomAuthController、WecomSettingsController、
     * WecomNotificationService 等）自始就引用这些字段，但原建表迁移遗漏了它们，
     * 导致 SQLite/MySQL 下访问企微相关页面均报 Unknown column。此迁移补齐。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wecom_userid')->nullable()->comment('企业微信 userid')->index();
            $table->timestamp('wecom_bound_at')->nullable()->comment('企业微信绑定时间');
            $table->boolean('wecom_notify_enabled')->default(true)->comment('是否开启企业微信通知');
            $table->text('wecom_notify_settings')->nullable()->comment('企业微信通知设置(JSON)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wecom_userid', 'wecom_bound_at', 'wecom_notify_enabled', 'wecom_notify_settings']);
        });
    }
};
