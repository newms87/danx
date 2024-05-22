<?php

namespace Newms87\Danx\Logging\Console;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Logger;

class ConsoleStreamFormatter extends NormalizerFormatter
{
	const string
		COLOR_DANGER = '31',
		COLOR_WARNING = '33',
		COLOR_GOOD = '32',
		COLOR_DEFAULT = '36';

	protected $levelColor;

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
	 * @param array $record A record to format
	 * @return string The formatted record
	 */
	public function format($record): string
	{
		$color = $this->getLevelColor($record['level']);

		return $this->formatColor($color, $record['message']) . "\n";
	}

	/**
	 * Resolve the Color that matches the log level
	 *
	 * @param $level
	 * @return string
	 */
	public function getLevelColor($level): string
	{
		return match (true) {
			$level >= Logger::ERROR => self::COLOR_DANGER,
			$level >= Logger::WARNING => self::COLOR_WARNING,
			$level >= Logger::INFO => self::COLOR_GOOD,
			default => self::COLOR_DEFAULT,
		};
	}

	/**
	 * Cast a message to a CLI interpreter escaped color
	 *
	 * @param $color
	 * @param $msg
	 * @return string
	 */
	public function formatColor($color, $msg): string
	{
		return "\033[{$color}m{$msg}\033[0m";
	}
}
