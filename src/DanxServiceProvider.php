<?php

namespace Newms87\Danx;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Newms87\Danx\Console\Commands\DanxLinkCommand;
use Newms87\Danx\Console\Commands\FixPermissions;
use Newms87\Danx\Console\Commands\SyncDirtyJobsCommand;
use Newms87\Danx\Console\Commands\VaporDecryptCommand;
use Newms87\Danx\Console\Commands\VaporEncryptCommand;
use Newms87\Danx\Listeners\LogCommandExecution;

require_once __DIR__ . '/../bootstrap/helpers.php';

class DanxServiceProvider extends ServiceProvider
{
	public function boot()
	{
		Event::listen(CommandStarting::class, LogCommandExecution::class);

		$this->mergeConfigFrom(__DIR__ . '/../config/danx.php', 'danx');

		$this->publishesMigrations([
			__DIR__ . '/../database/migrations' => database_path('migrations'),
		]);

		$this->publishes([
			__DIR__ . '/../.tinkerwell'     => base_path('.tinkerwell'),
			__DIR__ . '/../config/danx.php' => config_path('danx.php'),
		]);

		$this->publishes([
			__DIR__ . '/../database/factories' => database_path('factories'),
		], 'factories');

		if ($this->app->runningInConsole()) {
			$this->commands([
				DanxLinkCommand::class,
				FixPermissions::class,
				SyncDirtyJobsCommand::class,
				VaporDecryptCommand::class,
				VaporEncryptCommand::class,
			]);
		}
	}

	public function register()
	{
	}
}
