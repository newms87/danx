<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_api_log_id')->nullable()->after('id');
            $table->smallInteger('attempt_number')->default(1)->after('parent_api_log_id');
            $table->boolean('is_hedge_winner')->nullable()->after('attempt_number');

            $table->index('parent_api_log_id');

            $table->foreign('parent_api_log_id')->references('id')->on('api_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropForeign(['parent_api_log_id']);
            $table->dropIndex(['parent_api_log_id']);
            $table->dropColumn(['parent_api_log_id', 'attempt_number', 'is_hedge_winner']);
        });
    }
};
