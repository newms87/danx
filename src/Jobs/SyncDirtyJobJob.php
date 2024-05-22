<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Models\Job\SyncJob;

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
