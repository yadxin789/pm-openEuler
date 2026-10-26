<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI 委托授权 与 AI 变更提案（两阶段写入）。
     *
     * 原则：谁对这份文件拥有读 / 写 / 下载的权限，谁就可以授权 AI 读 / 写 / 下载。
     * AI 发起的任何编辑都必须先落成「待确认提案」，经授权人确认后才真正落库。
     */
    public function up(): void
    {
        // 用户委托令牌：由用户本人签发，令牌的权限边界 = 该用户自身的权限边界
        Schema::create('ai_delegation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->comment('令牌名称，便于用户分辨用途');
            $table->string('token_hash', 64)->unique()->comment('sha256(明文令牌)');
            $table->string('token_prefix', 12)->comment('明文前 8 位，仅用于列表展示');
            $table->json('scopes')->comment('授权范围：read / write / download');
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // AI 变更提案：AI 只负责「提议」，人确认后才「执行」
        Schema::create('ai_change_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()
                ->comment('授权人 = 提案审批人，权限判定以其身份为准');
            $table->foreignId('delegation_token_id')->nullable()->constrained('ai_delegation_tokens')->nullOnDelete();
            $table->string('resource_type')->comment('task / file / department_file / knowledge / knowledge_doc');
            $table->unsignedBigInteger('resource_id')->nullable()->comment('新增类操作为 null');
            $table->string('action')->comment('create / update / delete / upload / approve / reject / extension');
            $table->json('payload')->comment('AI 期望写入的字段');
            $table->json('before')->nullable()->comment('变更前快照，用于生成 diff');
            $table->json('after')->nullable()->comment('变更后预期结果，用于生成 diff');
            $table->string('status', 20)->default('pending')->comment('pending / confirmed / rejected / expired / failed');
            $table->text('reason')->nullable()->comment('AI 说明本次变更的理由');
            $table->string('confirm_token_hash', 64)->nullable()->comment('审批人确认凭据');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolve_note')->nullable();
            $table->json('result')->nullable()->comment('执行后的结果（新建 id / 错误信息等）');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_change_proposals');
        Schema::dropIfExists('ai_delegation_tokens');
    }
};
