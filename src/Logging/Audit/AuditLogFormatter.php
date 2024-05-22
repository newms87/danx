<?php

namespace Newms87\Danx\Logging\Audit;

use Exception;
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

		/** @var Exception $exception */
		$exception = $record['exception'] ?? $record['context']['exception'] ?? null;
		if ($exception) {
			$message = $exception::class . ': ' . $exception->getMessage() . ' --- ' . $exception->getFile() . '@' . $exception->getLine();
		} else {
			$message = (string)$record['message'];
		}

		return [
			'message'   => $message,
			'exception' => $exception,
		];
	}
}
