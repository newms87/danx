<?php

namespace Newms87\DanxLaravel\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DanxLinkCommand extends Command
{
	protected $signature   = 'danx:link';
	protected $description = 'Creates a symlink to the danx-laravel package from the vendors directory.';

	public function handle()
	{
		$path = base_path('vendor/newms87');

		(new Process(['rm', './danx-laravel'], $path))->mustRun();
		(new Process(['ln', '-s', '../../../danx-laravel', './danx-laravel'], $path))->mustRun();
		$process = (new Process(['ls', '-lah'], $path))->mustRun();
		$this->info($process->getOutput());
		$this->info('Danx Laravel symlinked to your project for local development!');
	}
}
