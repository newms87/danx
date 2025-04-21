<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up()
	{
		if (!Schema::hasTable('model_refs')) {
			Schema::create('model_refs', function (Blueprint $table) {
				$table->id();
				$table->string('prefix');
				$table->string('ref');
				$table->timestamps();

				$table->unique(['prefix', 'ref'], 'model_refs_prefix_ref_unique');
			});
		}
	}

	public function down()
	{
		Schema::dropIfExists('model_refs');
	}
};
