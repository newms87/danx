<?php

namespace Newms87\Danx\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class FixPermissions extends Command
{
	protected $signature   = 'fix';
	protected $description = 'Fix permissions for running the app in sail w/ Docker Desktop as there are issues mapping user/group ID';

	public function handle()
	{
		if (config('app.env') !== 'local') {
			$this->info('Permissions only need to be fixed in local development environments.');

			return;
		}

		$commands = [
			'chmod -Rf 777 storage',
			'chmod -Rf 777 bootstrap/cache',
			'chmod -Rf 777 app',
			'chmod -Rf 777 config',
			'chmod -Rf 777 database',
			'chmod -Rf 777 public',
			'chmod -Rf 777 routes',
			'chmod -Rf 777 resources',
			'chmod -Rf 777 vendor',
			'chmod -f 777 .',
			'chmod -f 777 composer.json',
		];

		foreach($commands as $command) {
			(new Process(explode(' ', $command), base_path('')))->run();
			$this->info("Executed: $command");
		}

		$this->info('Permissions fixed.');
	}
}
