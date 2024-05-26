<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up()
	{
		if (Schema::hasTable('job_dispatch')) {
			Schema::table('job_dispatch', function (Blueprint $table) {
				$table->json('data')->nullable()->after('count');
			});
		}
	}

	public function down()
	{
		if (Schema::hasTable('job_dispatch')) {
			Schema::whenTableHasColumn('job_dispatch', 'data', function (Blueprint $table) {
				$table->dropColumn('data');
			});
		}
	}
};
