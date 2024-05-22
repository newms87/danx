<?php

namespace Newms87\DanxLaravel\Jobs;

use Newms87\DanxLaravel\Models\Job\SyncJob;

class SyncDirtyJobJob extends Job
{
	protected SyncJob $syncJob;

	public function __construct(SyncJob $syncJob)
	{
		$this->syncJob = $syncJob;
		parent::__construct();
	}

	public function ref(): string
	{
		return 'sync-dirty-job:' . $this->syncJob->id;
	}

	public function run()
	{
		$this->syncJob->run();
	}
}
