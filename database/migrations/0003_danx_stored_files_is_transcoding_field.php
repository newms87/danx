<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up()
	{
		if (Schema::hasTable('stored_files')) {
			Schema::table('stored_files', function (Blueprint $table) {
				$table->boolean('is_transcoding')->default(false)->after('transcode_name')->index();
			});
		}
	}

	public function down()
	{
		if (Schema::hasTable('stored_files')) {
			Schema::table('stored_files', function (Blueprint $table) {
				$table->dropColumn('is_transcoding');
			});
		}
	}
};
