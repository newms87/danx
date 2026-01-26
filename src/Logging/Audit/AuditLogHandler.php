<?php

namespace Newms87\Danx\Logging\Audit;

use Exception;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Events\JobDispatchUpdatedEvent;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Models\Audit\ErrorLog;

/**
 * Writes entries to error_logs table
 */
class AuditLogHandler extends AbstractProcessingHandler
{
	/**
	 * Re-entrancy guard to prevent infinite recursion when logging triggers additional log calls
	 */
	private static bool $isWriting = false;

	public function __construct(
		$level = Logger::DEBUG,
		$bubble = true
	)
	{
		parent::__construct($level, $bubble);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $record
	 *
	 * @throws Exception
	 */
	protected function write($record): void
	{
		// Prevent infinite recursion if logging is triggered during write
		if (self::$isWriting) {
			return;
		}

		self::$isWriting = true;

		try {
			$this->doWrite($record);
		} finally {
			self::$isWriting = false;
		}
	}

	/**
	 * Perform the actual write operation
	 */
	protected function doWrite(LogRecord $record): void
	{
		$formatted = $record['formatted'];

		if ($formatted) {
			$level     = $record['level_name'];
			$message   = $formatted['message'];
			$exception = $formatted['exception'];

			$auditRequest = AuditDriver::getAuditRequest();

			if ($auditRequest) {
				$timestamp = now()->toDateTimeString();
				$entry     = "\n$timestamp $level $message";
				$entry     = StringHelper::logSafeString($entry, 100000);

				// Use atomic SQL concatenation - no application lock needed, database handles concurrency
				DB::statement(
					"UPDATE audit_request SET logs = COALESCE(logs, '') || ?, log_line_count = COALESCE(log_line_count, 0) + ? WHERE id = ?",
					[$entry, substr_count($entry, "\n"), $auditRequest->id]
				);

				// Dispatch JobDispatchUpdatedEvent so UI sees log updates in real-time
				// (raw DB::statement bypasses Eloquent model events, so we dispatch manually)
				foreach ($auditRequest->ranJobs as $jobDispatch) {
					JobDispatchUpdatedEvent::dispatch($jobDispatch, 'updated');
				}
			}

			$levelInt = ErrorLog::getLevelInt($level);

			if ($exception) {
				ErrorLog::logException($levelInt, $exception);
			} elseif ($levelInt >= Level::Error) {
				ErrorLog::logErrorMessage($level, $message);
			}
		}
	}
}
