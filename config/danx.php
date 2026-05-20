<?php

return [
	'models' => [
		'team' => \Newms87\Danx\Models\Team\Team::class,
	],

	'encryption' => [
		'key' => env('LARAVEL_ENV_ENCRYPTION_KEY'),
	],

	'transcode' => [
		// Automatically transcode PDF files to images uploading
		'pdf_to_images' => env('TRANSCODE_PDF_TO_IMAGES', false),
	],

	'process_fork' => [
		/**
		 * Default cap for ProcessFork::run() when callers don't pass an explicit
		 * maxConcurrent. Each fork inherits a fresh DB connection — unbounded fan-out
		 * saturates PostgreSQL's `max_connections` (default 100) and individual
		 * children fail with "too many clients already" mid-task.
		 *
		 * 16 = safe under PG's default 100-connection cap (Horizon + Octane + web
		 * + scheduler + a couple of orchestrator forks share the pool). Override via
		 * DANX_PROCESS_FORK_MAX_CONCURRENT when the deployment scales PG limits.
		 */
		'max_concurrent' => (int) env('DANX_PROCESS_FORK_MAX_CONCURRENT', 16),
	],

	'audit'               => [
		'enabled' => env('AUDIT_ENABLED', env('AUDITING_ENABLED', false)),

		/**
		 * Enables debugging for the audit log (normally only enabled when trying to figure out what went wrong w/ auditing)
		 */
		'debug'   => env('AUDIT_DEBUG', false),

		/**
		 * Enable auditing / logging for any Api implementations using the Api class
		 */
		'api'     => [
			'enabled'         => env('AUDIT_API_ENABLED', false),
			'max_body_length' => env('AUDIT_API_MAX_BODY_LENGTH', 100000),
		],

		/**
		 * Enable auditing / logging for any Jobs implementations using the Job class
		 */
		'jobs'    => [
			'enabled' => env('AUDIT_JOBS_ENABLED', false),
			'debug'   => env('AUDIT_JOBS_DEBUG', false),
		],

		/**
		 * Heartbeat process that monitors long-running API requests and logs if the parent process dies unexpectedly
		 */
		'heartbeat' => [
			'enabled'  => env('JOB_HEARTBEAT_ENABLED', true),
			'interval' => env('JOB_HEARTBEAT_INTERVAL', 10),
		],
	],

	/*
	 * AWS ELB application load balancer (ALB) has a 1MB limit on the response size when used w/ Lambda
	 * This should be enabled for the Laravel Vapor environment
	 */
	'response_size_limit' => [
		'enabled'    => env('RESPONSE_SIZE_LIMIT_ENABLED', false),
		'limit'      => env('RESPONSE_SIZE_LIMIT', 1024 * 1024),
		'disk'       => env('RESPONSE_SIZE_LIMIT_DISK', 's3'),

		// If the response file should be served via a CDN, setting the alias / origin will rewrite the URL of the file to use the CDN
		'cdn_origin' => env('RESPONSE_SIZE_LIMIT_CDN_ORIGIN'),
		'cdn_alias'  => env('RESPONSE_SIZE_LIMIT_CDN_ALIAS'),
	],

	'logging' => [
		'output_exception_traces' => env('LOG_OUTPUT_EXCEPTION_TRACES', false),
	],

	/*
	 * Error handling configuration for API requests and job-level retries.
	 */
	'errors' => [
		// Service class for checking API error retryability (must have static isRetryable(Throwable): bool)
		'api_retryable_checker' => null,

		// Service class for checking job error retryability (for task process restarts)
		'job_retryable_checker' => null,

		// Default retry count for API requests (0 = no retries)
		'api_retry_count' => (int) env('API_RETRY_COUNT', 3),

		// Delay between retry attempts in milliseconds
		'api_retry_delay_ms' => (int) env('API_RETRY_DELAY_MS', 1000),
	],
];
