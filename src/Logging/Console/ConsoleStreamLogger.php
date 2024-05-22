<?php

namespace Newms87\DanxLaravel\Logging\Console;

use Exception;
use Monolog\Logger;

class ConsoleStreamLogger
{
	/**
	 * Create a custom Monolog instance.
	 *
	 * @param array $config
	 * @return Logger
	 * @throws Exception
	 */
	public function __invoke(array $config)
	{
		$logger = new Logger('custom');

		$handler = new ConsoleStreamHandler(
			$config['stream'] ?? 'php://stdout',
			$config['level'] ?? Logger::ERROR,
			$config['bubble'] ?? true,
			$config['filePermission'] ?? null,
			$config['useLocking'] ?? false
		);

		// Setup the CustomSlackFormatter by default
		$formatterClass = $config['formatter'] ?? ConsoleStreamFormatter::class;
		$formatter      = new $formatterClass();
		$handler->setFormatter($formatter);

		$logger->pushHandler($handler);

		return $logger;
	}
}
