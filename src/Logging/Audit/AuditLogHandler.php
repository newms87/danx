<?php

namespace Newms87\Danx\Logging\Audit;

use Exception;
use Illuminate\Support\Facades\Cache;
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

	/**
	 * Cached trace-enabled flag and timestamp for 10-second TTL check.
	 * When trace is enabled, this handler accepts DEBUG-level messages
	 * regardless of its configured minimum level.
	 */
	private static bool   $traceEnabled   = false;
	private static ?float $traceCheckedAt = null;

	public function __construct(
		$level = Logger::DEBUG,
		$bubble = true
	)
	{
		parent::__construct($level, $bubble);
	}

	/**
	 * Override level gating to support dynamic TRACE toggle.
	 * When trace is enabled via cache, accept DEBUG-level messages
	 * even if the handler's configured minimum is higher.
	 */
	public function isHandling(LogRecord $record): bool
	{
		$now = microtime(true);

		if (self::$traceCheckedAt === null || ($now - self::$traceCheckedAt) > 10) {
			self::$traceEnabled   = (bool)Cache::get('debug:trace_enabled');
			self::$traceCheckedAt = $now;
		}

		if (self::$traceEnabled && $record->level->value >= Level::Debug->value) {
			return true;
		}

		return parent::isHandling($record);
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
	 * Perform the actual write operation: append the line to the current AuditRequest's
	 * logs, then record it in error_logs when it is an error.
	 *
	 * A line carrying an exception is recorded by ErrorLog::logException() (which keeps
	 * anything above INFO); a message-only line is recorded by ErrorLog::logErrorMessage()
	 * when its level is ERROR or higher. A line flagged ErrorLog::RECORDED_CONTEXT_KEY is the
	 * one logException() writes after recording the error itself, so it is not recorded again.
	 *
	 * $record->level is a Monolog\Level enum. Compare its ->value (or use the enum's own
	 * isLowerThan()/includes()), never the enum itself against an int: PHP evaluates
	 * `int >= Level::Error` as false for every int, which is exactly how message-only
	 * errors silently stopped being recorded.
	 */
	protected function doWrite(LogRecord $record): void
	{
		$formatted = $record['formatted'];

		if ($formatted) {
			$level     = $record->level->getName();
			$levelInt  = $record->level->value;
			$message   = $formatted['message'];
			$exception = $formatted['exception'];

			$auditRequest = AuditDriver::getAuditRequest();

			if ($auditRequest) {
				$timestamp = now()->format('Y-m-d H:i:s.v');
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

			if (!empty($record->context[ErrorLog::RECORDED_CONTEXT_KEY])) {
				// ErrorLog::logException() wrote this line after recording the error itself
				return;
			}

			if ($exception) {
				ErrorLog::logException($levelInt, $exception);
			} elseif (!$record->level->isLowerThan(Level::Error)) {
				ErrorLog::logErrorMessage($levelInt, $message);
			}
		}
	}
}
