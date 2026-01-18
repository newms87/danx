<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->unsignedInteger('status_code')->nullable()->change();
            $table->timestamp('will_timeout_at', 3)->nullable()->after('finished_at');
            $table->index(['will_timeout_at', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropIndex(['will_timeout_at', 'status_code']);
            $table->dropColumn('will_timeout_at');
            $table->unsignedInteger('status_code')->nullable(false)->change();
        });
    }
};
