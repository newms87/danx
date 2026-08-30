<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table('api_logs', function (Blueprint $table) {
			$table->timestamp('started_at', 3)->nullable()->after('stack_trace');
			$table->timestamp('finished_at', 3)->nullable()->after('started_at');
			// Nullable double precision, matching the REAL column on every existing
			// installation (verified via information_schema on gpt-manager's local dev +
			// production, 2026-08-29/30). This declaration previously said `integer` NOT
			// NULL, which was never actually true anywhere: between 2025-04-26 and
			// 2025-05-13 this column was briefly a generated `float`/`double precision`
			// STORED column
			// (`storedAs('COALESCE(EXTRACT(EPOCH FROM (finished_at - started_at)) * 1000, 0)')`),
			// and once that version of this migration had run against a real database,
			// Laravel never re-ran it after the file was later rewritten to this plain form
			// (already-run migrations are tracked by filename, not content). Nullable is
			// also semantically correct: a row created via ApiLog::logRequest() that never
			// reaches logResponse()/logResponseError() (in-flight request, abandoned hedge
			// race loser, or a crash before completion) legitimately has no run time yet -
			// a consuming app's production database can carry live NULL rows in exactly
			// this shape.
			$table->double('run_time_ms')->nullable()->after('finished_at');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::table('api_logs', function (Blueprint $table) {
			$table->dropColumn('run_time_ms');
			$table->dropColumn(['started_at', 'finished_at']);
		});
	}
};
