<?php

namespace Newms87\Danx\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DanxLinkCommand extends Command
{
	protected $signature   = 'danx:link';
	protected $description = 'Creates a symlink to the danx package from the vendors directory.';

	public function handle()
	{
		$this->call('fix');
		$path = base_path('vendor/newms87');

		(new Process(['rm', '-R', './danx'], $path))->mustRun();
		(new Process(['ln', '-s', '../../../danx', './danx'], $path))->mustRun();
		$process = (new Process(['ls', '-lah'], $path))->mustRun();
		$this->info($process->getOutput());
		$this->info('Danx symlinked to your project for local development!');
	}
}
