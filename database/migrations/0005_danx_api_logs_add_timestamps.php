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
			$table->integer('run_time_ms')->after('finished_at');
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
