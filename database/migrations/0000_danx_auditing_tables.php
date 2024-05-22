<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up()
	{
		if (!Schema::hasTable('api_logs')) {
			Schema::create('api_logs', function (Blueprint $table) {
				$table->bigIncrements('id');
				$table->unsignedInteger('audit_request_id')->nullable();
				$table->unsignedInteger('user_id')->nullable()->index();
				$table->string('api_class');
				$table->string('service_name');
				$table->unsignedInteger('status_code');
				$table->string('method');
				$table->string('url', 512)->index();
				$table->text('full_url');
				$table->json('request')->nullable();
				$table->json('response')->nullable();
				$table->json('request_headers')->nullable();
				$table->json('response_headers')->nullable();
				$table->json('stack_trace')->nullable();
				$table->timestamps();

				$table->index(['api_class', 'status_code', 'method']);
				$table->index(['service_name', 'status_code', 'method']);
			});
		}

		if (!Schema::hasTable('audit_request')) {
			Schema::create('audit_request', function (Blueprint $table) {
				$table->increments('id');
				$table->string('session_id')->index();
				$table->unsignedInteger('user_id')->nullable()->index();
				$table->string('environment')->nullable()->index();
				$table->string('url', 512)->nullable()->index();
				$table->json('request');
				$table->json('response')->nullable();
				$table->text('logs')->nullable();
				$table->text('profile')->nullable();
				$table->double('time');
				$table->timestamps();
			});
		}

		if (!Schema::hasTable('audits')) {
			Schema::create('audits', function (Blueprint $table) {
				$table->increments('id');
				$table->unsignedInteger('audit_request_id');
				$table->unsignedBigInteger('user_id')->nullable();
				$table->string('event');
				$table->string('auditable_type');
				$table->char('auditable_id');
				$table->json('old_values');
				$table->json('new_values');
				$table->text('tags')->nullable();
				$table->timestamps();

				$table->index(['auditable_type', 'auditable_id']);
				$table->index(['user_id']);
			});
		}


		if (!Schema::hasTable('error_log_entry')) {
			Schema::create('error_log_entry', function (Blueprint $table) {
				$table->bigIncrements('id');
				$table->unsignedBigInteger('error_log_id');
				$table->unsignedInteger('audit_request_id')->nullable();
				$table->unsignedInteger('user_id')->nullable();
				$table->string('message', 512)->default('');
				$table->longText('full_message')->nullable();
				$table->json('data')->nullable();
				$table->timestamp('created_at')->nullable()->index();
				$table->timestamp('updated_at')->nullable();

				$table->index(['user_id', 'created_at']);
			});
		}

		if (!Schema::hasTable('error_logs')) {
			Schema::create('error_logs', function (Blueprint $table) {
				$table->bigIncrements('id');
				$table->unsignedBigInteger('root_id')->nullable();
				$table->unsignedBigInteger('parent_id')->nullable();
				$table->string('hash')->unique();
				$table->string('error_class')->index();
				$table->string('code');
				$table->string('level');
				$table->string('message', 512)->index();
				$table->string('file', 512)->nullable();
				$table->unsignedInteger('line')->nullable();
				$table->unsignedInteger('count');
				$table->dateTime('last_seen_at');
				$table->dateTime('last_notified_at')->nullable();
				$table->boolean('send_notifications')->default(true);
				$table->json('stack_trace')->nullable();
				$table->timestamps();

				$table->index(['level', 'code', 'error_class']);
			});
		}

		if (!Schema::hasTable('job_batches')) {
			Schema::create('job_batches', function (Blueprint $table) {
				$table->string('id')->primary();
				$table->string('name');
				$table->integer('total_jobs');
				$table->integer('pending_jobs');
				$table->integer('failed_jobs');
				$table->text('failed_job_ids');
				$table->mediumText('options')->nullable();
				$table->text('on_complete')->nullable();
				$table->integer('cancelled_at')->nullable();
				$table->integer('created_at');
				$table->integer('finished_at')->nullable();
			});
		}

		if (!Schema::hasTable('job_dispatch')) {
			Schema::create('job_dispatch', function (Blueprint $table) {
				$table->increments('id');
				$table->unsignedBigInteger('user_id')->nullable();
				$table->string('name')->nullable()->index('category');
				$table->string('ref')->index('job_id');
				$table->string('job_batch_id')->nullable();
				$table->unsignedInteger('running_audit_request_id')->nullable();
				$table->unsignedInteger('dispatch_audit_request_id')->nullable();
				$table->string('status');
				$table->dateTime('ran_at')->nullable();
				$table->dateTime('completed_at')->nullable();
				$table->dateTime('timeout_at')->nullable();
				$table->integer('run_time')->nullable()->storedAs('timestampdiff(SECOND,`ran_at`,`completed_at`)');
				$table->unsignedInteger('count');
				$table->timestamps();
			});
		}


		try {
			Schema::table('api_logs', function (Blueprint $table) {
				$table->foreign(['audit_request_id'])->references(['id'])->on('audit_request')->onUpdate('no action')->onDelete('no action');
			});
		} catch(Throwable $e) {
			// Ignore
		}

		try {
			Schema::table('audits', function (Blueprint $table) {
				$table->foreign(['audit_request_id'])->references(['id'])->on('audit_request')->onUpdate('no action')->onDelete('no action');
			});
		} catch(Throwable $e) {
			// Ignore
		}

		try {
			Schema::table('error_log_entry', function (Blueprint $table) {
				$table->foreign(['audit_request_id'])->references(['id'])->on('audit_request')->onUpdate('no action')->onDelete('no action');
				$table->foreign(['error_log_id'])->references(['id'])->on('error_logs')->onUpdate('no action')->onDelete('no action');
			});
		} catch(Throwable $e) {
			// Ignore
		}

		try {
			Schema::table('error_logs', function (Blueprint $table) {
				$table->foreign(['parent_id'])->references(['id'])->on('error_logs')->onUpdate('no action')->onDelete('no action');
				$table->foreign(['root_id'])->references(['id'])->on('error_logs')->onUpdate('no action')->onDelete('no action');
			});
		} catch(Throwable $e) {
			// Ignore
		}

		try {
			Schema::table('job_dispatch', function (Blueprint $table) {
				$table->foreign(['job_batch_id'])->references(['id'])->on('job_batches')->onUpdate('no action')->onDelete('no action');
				$table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('no action');
			});
		} catch(Throwable $e) {
			// Ignore
		}
	}

	public function down()
	{
		Schema::table('job_dispatch', function (Blueprint $table) {
			$table->dropForeign('job_dispatch_job_batch_id_foreign');
			$table->dropForeign('job_dispatch_user_id_foreign');
		});

		Schema::table('error_logs', function (Blueprint $table) {
			$table->dropForeign('error_logs_parent_id_foreign');
			$table->dropForeign('error_logs_root_id_foreign');
		});

		Schema::table('error_log_entry', function (Blueprint $table) {
			$table->dropForeign('error_log_entry_audit_request_id_foreign');
			$table->dropForeign('error_log_entry_error_log_id_foreign');
		});

		Schema::table('audits', function (Blueprint $table) {
			$table->dropForeign('audits_audit_request_id_foreign');
		});

		Schema::table('api_logs', function (Blueprint $table) {
			$table->dropForeign('api_logs_audit_request_id_foreign');
		});

		Schema::dropIfExists('job_dispatch');

		Schema::dropIfExists('job_batches');

		Schema::dropIfExists('error_logs');

		Schema::dropIfExists('error_log_entry');

		Schema::dropIfExists('audits');

		Schema::dropIfExists('audit_request');

		Schema::dropIfExists('api_logs');
	}
};
