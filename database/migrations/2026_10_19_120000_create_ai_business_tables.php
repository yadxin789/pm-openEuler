<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI 全局配置（单行，与 ERP 参考实现一致）
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->default('deepseek');
            $table->string('api_key', 500)->nullable();
            $table->string('base_url', 500)->nullable();
            $table->string('model', 100)->default('deepseek-chat');
            $table->boolean('vision_enabled')->default(false);
            $table->string('vision_model', 100)->nullable();
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->integer('max_tokens')->default(2000);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // 文档生成 Skill：模板文件 / 范例文件 / 支援文件 目录
        Schema::create('ai_doc_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);                    // 文档类型名称（如：项目立项报告）
            $table->string('category', 80)->default('通用'); // 分类
            $table->text('description')->nullable();         // 用途说明
            $table->string('template_path', 300)->nullable(); // 模板文件相对 storage/app/ai_docs 的路径
            $table->string('example_path', 300)->nullable();  // 范例文件路径
            $table->json('support_files')->nullable();        // 支援文件列表
            $table->text('prompt_guide')->nullable();          // 生成指引（占位符说明、写作要点）
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // AI Skill 目录（PM 专业服务能力注册表）
        Schema::create('ai_skills', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('type', 30)->default('tool'); // tool|agent|doc-generator
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_skills');
        Schema::dropIfExists('ai_doc_templates');
        Schema::dropIfExists('ai_settings');
    }
};
