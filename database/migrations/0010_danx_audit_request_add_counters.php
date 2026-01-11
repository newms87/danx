<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_request', function (Blueprint $table) {
            $table->unsignedInteger('api_log_count')->default(0);
            $table->unsignedInteger('error_log_count')->default(0);
            $table->unsignedInteger('log_line_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('audit_request', function (Blueprint $table) {
            $table->dropColumn(['api_log_count', 'error_log_count', 'log_line_count']);
        });
    }
};
