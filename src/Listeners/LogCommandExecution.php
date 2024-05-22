<?php

namespace Newms87\Danx\Listeners;

use Newms87\Danx\Audit\AuditDriver;

class LogCommandExecution
{
	public function handle(object $event): void
	{
		$commandName = $event->command;
		$params      = $event->input->getArguments();

		AuditDriver::getAuditRequest()?->update([
			'request' => [
				'command' => $commandName,
				'params'  => $params,
			],
		]);
	}
}
