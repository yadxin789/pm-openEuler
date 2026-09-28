<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('extension_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('applicant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('approver_id')->constrained('users')->onDelete('cascade');
            $table->integer('days');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
            
            // 性能索引
            $table->index('task_id', 'ext_app_task_idx');
            $table->index('applicant_id', 'ext_app_applicant_idx');
            $table->index('approver_id', 'ext_app_approver_idx');
            $table->index('status', 'ext_app_status_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('extension_applications');
    }
};