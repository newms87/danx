<?php

namespace Newms87\Danx\Logging\Audit;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class AuditLogFormatter extends NormalizerFormatter
{
	/**
	 * Formats a set of log records.
	 *
	 * @param array $records A set of records to format
	 * @return array The formatted set of records
	 */
	public function formatBatch(array $records): array
	{
		$formatted = [];

		foreach($records as $record) {
			$formatted[] = $this->format($record);
		}

		return $formatted;
	}

	/**
	 * Formats a log record.
	 *
	 * @param array|LogRecord $record A record to format
	 * @return array The formatted record
	 */
	public function format($record): array
	{
		if ($record instanceof LogRecord) {
			$record = $record->toArray();
		}

		$exception = $record['exception'] ?? $record['context']['exception'] ?? null;
		if ($exception instanceof \Throwable) {
			$message = $exception::class . ': ' . $exception->getMessage() . ' --- ' . $exception->getFile() . '@' . $exception->getLine();
		} elseif ($exception) {
			$message = (string)$exception;
		} else {
			$message = (string)$record['message'];
		}

		return [
			'message'   => $message,
			// Only return exception if it's a Throwable - strings can't be passed to ErrorLog::logException()
			'exception' => $exception instanceof \Throwable ? $exception : null,
		];
	}
}
