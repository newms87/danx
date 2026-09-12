<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a queued dispatch's message becomes deliverable — the moment it was queued, or the end of
 * its delay. Job::dispatch() reads it to decide whether a dispatch may fold into the ref's Pending
 * row (it runs no later than asked) or must supersede it (it would wait out someone else's delay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_dispatch', function (Blueprint $table) {
            $table->dateTime('available_at', 3)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('job_dispatch', function (Blueprint $table) {
            $table->dropColumn('available_at');
        });
    }
};
