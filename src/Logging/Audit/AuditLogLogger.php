<?php

namespace Newms87\DanxLaravel\Logging\Audit;

use Exception;
use Monolog\Logger;

class AuditLogLogger
{
	/**
	 * Create a custom Monolog instance.
	 *
	 * @param array $config
	 * @return Logger
	 *
	 * @throws Exception
	 */
	public function __invoke(array $config): Logger
	{
		$logger = new Logger('custom');

		$handler = new AuditLogHandler($config['level'] ?? Logger::DEBUG);

		$handler->setFormatter(new AuditLogFormatter);
		$logger->pushHandler($handler);

		return $logger;
	}
}
