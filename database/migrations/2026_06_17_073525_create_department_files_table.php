<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('department_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->onDelete('cascade');
            $table->foreignId('uploader_id')->constrained('users');
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->bigInteger('size');
            $table->string('mime_type');
            // 文件分类：template, example, product_spec, component_spec, safety, certification, patent
            $table->string('category')->default('template')->comment('文件分类：template-模板文件，example-范例文件，product_spec-产品规格书，component_spec-元器件规格书，safety-安规标准，certification-认证类，patent-专利类');
            // 资料文件权限范围：all-全部用户，department-本部门用户，private-仅上传人。
            $table->string('visibility')->default('all')->comment('权限范围：all-全部用户，department-本部门用户，private-仅上传人');
            $table->integer('version')->default(1)->comment('版本号');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('department_files');
    }
};