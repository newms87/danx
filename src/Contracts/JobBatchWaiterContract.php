<?php

namespace Newms87\Danx\Contracts;

use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Models\Job\JobBatch;

/**
 * A model that can pause itself waiting on one or more JobBatches and resume once every
 * batch it is waiting on has settled (see JobBatch::createForJobs()'s $waiter parameter).
 *
 * Implementers are expected to guard resumeFromJobBatch() against being called on a model
 * that is no longer in a state that should resume (e.g. failed/cancelled independently
 * while its batch was still in flight) — settlement only guarantees "every batch this
 * waiter registered for has finished," not "the waiter itself is still waiting."
 */
interface JobBatchWaiterContract
{
	/**
	 * Called once every JobBatch this waiter is registered against (via
	 * JobBatch::createForJobs()'s $waiter param) has settled. $anyShardFailed is true if
	 * ANY settled batch (not just the last one) had at least one failed job — implementers
	 * must never blindly resume assuming success.
	 */
	public function resumeFromJobBatch(JobBatch $jobBatch, bool $anyShardFailed): void;
}

/**
 * Type alias for the Model + JobBatchWaiterContract intersection every real waiter must
 * satisfy — JobBatch's waiter-resolution helpers rely on Eloquent's getKey()/find().
 *
 * @phpstan-type JobBatchWaiterModel Model&JobBatchWaiterContract
 */
