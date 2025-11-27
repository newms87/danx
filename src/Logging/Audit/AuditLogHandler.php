<?php

namespace Newms87\Danx\Logging\Audit;

use Exception;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Models\Audit\ErrorLog;

/**
 * Writes entries to error_logs table
 */
class AuditLogHandler extends AbstractProcessingHandler
{
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
		$formatted = $record['formatted'];

		if ($formatted) {
			$level     = $record['level_name'];
			$message   = $formatted['message'];
			$exception = $formatted['exception'];

			$auditRequest = AuditDriver::getAuditRequest();

			if ($auditRequest) {
				$timestamp = now()->toDateTimeString();
				$entry     = "\n$timestamp $level $message";

				// DO NOT save here, the logs will be written when the terminate event is fired
				$auditRequest->logs = StringHelper::logSafeString($auditRequest->logs . $entry, 1000000);

				// Make an exception for running jobs to ensure we're getting logging leading up to an error
				$auditRequest->save();
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
