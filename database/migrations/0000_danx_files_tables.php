<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up()
	{
		if (!Schema::hasTable('stored_files')) {
			Schema::create('stored_files', function (Blueprint $table) {
				$table->uuid('id')->primary();
				$table->string('disk', 36);
				$table->string('filepath', 768)->index('file_filepath_index');
				$table->string('filename', 255);
				$table->string('url', 512)->nullable();
				$table->string('mime', 255);
				$table->unsignedInteger('size')->default(0);
				$table->json('exif')->nullable();
				$table->json('meta')->nullable();
				$table->json('location')->nullable();
				$table->unsignedInteger('page_number')->nullable();
				$table->string('transcode_name')->nullable();
				$table->uuid('original_stored_file_id')->nullable();
				$table->timestamps();
				$table->timestamp('deleted_at')->nullable();
			});

			Schema::table('stored_files', function (Blueprint $table) {
				$table->foreign('original_stored_file_id')->references('id')->on('stored_files')->nullOnDelete();
			});
		}

		if (!Schema::hasTable('stored_file_storables')) {
			Schema::create('stored_file_storables', function (Blueprint $table) {
				$table->bigIncrements('id');
				$table->uuid('stored_file_id');
				$table->morphs('storable');
				$table->string('category', 255)->nullable();
				$table->timestamps();
				$table->unique(['storable_id', 'storable_type', 'stored_file_id'], 'stored_file_storables_unique');
			});

			Schema::table('stored_file_storables', function (Blueprint $table) {
				$table->foreign('stored_file_id')->references('id')->on('stored_files')->cascadeOnDelete();
			});
		}
	}

	public function down()
	{
		Schema::dropIfExists('stored_files');
		Schema::dropIfExists('stored_file_storables');
	}
};
