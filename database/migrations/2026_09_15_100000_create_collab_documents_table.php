<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('name')->comment('文档显示名（不含扩展名）');
            $table->string('extension', 10)->comment('扩展名 docx/xlsx/pptx');
            $table->string('storage_path')->comment('file_repository 磁盘相对路径');
            $table->string('doc_key')->comment('OnlyOffice 文档 key，内容变更后更新');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'deleted_at'], 'collab_docs_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collab_documents');
    }
};
