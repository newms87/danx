<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->string('endpoint', 255)->nullable()->after('service_name');
            $table->index(['api_class', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropIndex(['api_class', 'endpoint']);
            $table->dropColumn('endpoint');
        });
    }
};
