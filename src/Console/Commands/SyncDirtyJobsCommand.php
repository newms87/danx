<?php

namespace Newms87\DanxLaravel\Console\Commands;

use Newms87\DanxLaravel\Jobs\SyncDirtyJobJob;
use Newms87\DanxLaravel\Models\Job\SyncJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDirtyJobsCommand extends Command
{
	protected $signature   = 'sync:dirty-jobs';
	protected $description = 'Sync any dirty SyncJob records';

	/**
	 * @throws Throwable
	 */
	public function handle()
	{
		// All dirty Sync Jobs that have not been dispatched in the last 30 minutes and have less than 3 attempts
		$dirtySyncJobs = SyncJob::whereNotNull('dirty_at')
			->whereDoesntHave('jobDispatch',
				fn(Builder $builder) => $builder->where('created_at', '>', now()->subMinutes(30)))
			->where('attempts', '<', 3)
			->get();

		foreach($dirtySyncJobs as $syncJob) {
			try {
				$job = (new SyncDirtyJobJob($syncJob))->dispatch();

				// If we don't get a job, that probably means there is an identical job already set to run
				if (!$job) {
					continue;
				}

				$syncJob->job_dispatch_id = $job->getJobDispatch()->id;
			} catch(Throwable $e) {
				Log::error("Failed to dispatch job for SyncJob {$syncJob->id}: {$e->getMessage()}");
			}
			$syncJob->attempts += 1;
			$syncJob->save();
		}

		if (now()->startOfMinute()->isMidnight()) {
			$failedSyncJobs = SyncJob::where('attempts', '>=', 3)->pluck('name', 'id');
			$syncJobList    = $failedSyncJobs->map(fn($name, $id) => "$id: $name")->implode("\n");
			Log::error("There are " . $failedSyncJobs->count() . " failed SyncJobs:\n\n$syncJobList");
		}
	}
}
