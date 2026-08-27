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
			// NOTE (test fixture only — NOT a copy-paste error): the real committed
			// migration text here has no ->nullable(), but a live query against the
			// real production schema (information_schema.columns) confirmed
			// run_time_ms IS nullable there — a pre-existing drift between this
			// migration file and deployed reality, unrelated to the hedging feature.
			// This fixture matches REAL reality so ApiLog::logRequest()'s initial
			// partial insert (run_time_ms not yet known) succeeds here exactly as it
			// does in production.
			$table->integer('run_time_ms')->nullable()->after('finished_at');
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
