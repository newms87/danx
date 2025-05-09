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
		if (!Schema::hasTable('job_dispatchables')) {
			Schema::create('job_dispatchables', function (Blueprint $table) {
				$table->id();
				$table->foreignId('job_dispatch_id')->constrained('job_dispatch')->cascadeOnDelete();
				$table->string('category')->default('');
				$table->string('model_type');
				$table->unsignedBigInteger('model_id');
				$table->timestamps();

				$table->index(['model_type', 'model_id']);
			});
		}
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::dropIfExists('job_dispatchables');
	}
};
