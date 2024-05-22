<?php

namespace Newms87\DanxLaravel\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class FixPermissions extends Command
{
	protected $signature   = 'fix';
	protected $description = 'Fix permissions for running the app in sail w/ Docker Desktop as there are issues mapping user/group ID';

	public function handle()
	{
		$commands = [
			'chmod -R 777 storage',
			'chmod -R 777 bootstrap/cache',
			'chmod -R 777 app',
			'chmod -R 777 config',
			'chmod -R 777 database',
			'chmod -R 777 public',
			'chmod -R 777 routes',
			'chmod -R 777 resources',
			'chmod 777 .',
			'chmod 777 composer.json',
		];

		foreach($commands as $command) {
			(new Process(explode(' ', $command), base_path('')))->mustRun();
			$this->info("Executed: $command");
		}

		$this->info('Permissions fixed.');
	}
}
