<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('collab_documents', function (Blueprint $table) {
            $table->boolean('is_temp_demo')->default(false)->after('storage_path');
            $table->timestamp('expires_at')->nullable()->after('is_temp_demo');
        });
    }

    public function down(): void
    {
        Schema::table('collab_documents', function (Blueprint $table) {
            $table->dropColumn(['is_temp_demo', 'expires_at']);
        });
    }
};
