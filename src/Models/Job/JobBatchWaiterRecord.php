<?php

namespace Newms87\Danx\Models\Job;

use Illuminate\Database\Eloquent\Model;

/**
 * One row = "this $waiter_type/$waiter_id is waiting on JobBatch #$job_batch_id."
 *
 * Created by JobBatch::addWaiter() (called from JobBatch::createForJobs() when a $waiter is
 * given), before any of the batch's jobs are dispatched -- this guarantees the row exists
 * even if a job settles synchronously before createForJobs() returns.
 *
 * $resolved_at is set exactly once, by JobBatch::resolveWaiterForJobBatch(), when this
 * specific batch settles -- never write it from anywhere else. A $waiter_type/$waiter_id
 * pair may have several rows in flight at once (e.g. concurrent fork children each
 * dispatching their own shard batch for the same waiter); the waiter only resumes once
 * every row for its type/id pair has a non-null $resolved_at.
 */
class JobBatchWaiterRecord extends Model
{
	protected $table = 'job_batch_waiters';

	protected $guarded    = [];
	public    $timestamps = false;

	protected function casts(): array
	{
		return [
			'resolved_at' => 'datetime',
			'created_at'  => 'datetime',
		];
	}
}
