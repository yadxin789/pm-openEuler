<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pid')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->string('title', 100);
            $table->string('desc', 255)->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('knowledges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('cate_id')->nullable();
            $table->string('title', 200);
            $table->string('desc', 500)->nullable();
            $table->unsignedTinyInteger('is_share')->default(1);
            $table->unsignedInteger('read')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('knowledge_docs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('knowledge_id');
            $table->unsignedBigInteger('pid')->default(0);
            $table->unsignedTinyInteger('type')->default(1);
            $table->string('title', 200);
            $table->text('md_content')->nullable();
            $table->longText('content')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('file_ids', 500)->nullable();
            $table->unsignedInteger('read')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['knowledge_id', 'pid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_docs');
        Schema::dropIfExists('knowledges');
        Schema::dropIfExists('knowledge_categories');
    }
};