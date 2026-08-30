<?php

namespace Newms87\Danx\Models\Job;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Newms87\Danx\Contracts\JobBatchWaiterContract;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Models\ModelRef;
use Throwable;

class JobBatch extends Model
{
	protected $table = 'job_batches';

	protected $guarded    = [];
	public    $timestamps = false;

	// Always include processed_jobs and progress in results
	protected $appends = ['processed_jobs', 'progress'];

	protected function casts(): array
	{
		return [
			'created_at' => 'datetime',
		];
	}

	/**
	 * @param string                               $name
	 * @param Job[]                                $jobs
	 * @param callable|null                        $onComplete
	 * @param (Model&JobBatchWaiterContract)|null  $waiter Registers this model as waiting on
	 *        the new batch (via a job_batch_waiters row) and automatically calls its
	 *        resumeFromJobBatch() once EVERY batch it's currently registered against has
	 *        settled -- not just this one. Safe to call createForJobs() several times
	 *        concurrently for the SAME $waiter (e.g. from separate ProcessFork children);
	 *        the waiter only resumes after the LAST of them settles.
	 * @param callable|null $beforeDispatch Invoked with the created JobBatch after the batch
	 *        exists, the waiter (if any) is registered, and every job's JobDispatch has been
	 *        associated -- but BEFORE any job is dispatched. This is the only safe moment for
	 *        a caller to record its own "I am waiting on batch N" state.
	 *
	 *        WHY THIS HOOK EXISTS: this method dispatches from inside its own call, so a
	 *        caller doing its bookkeeping on the returned JobBatch is racing the jobs. Under
	 *        a synchronous queue driver the first job can run to completion -- and, if it is
	 *        the batch's last pending job, invoke on_complete -- before this method returns.
	 *        A waiter whose "waiting" marker is written afterwards is not yet waiting when
	 *        the completion callback looks at it, so the callback declines to resume it and
	 *        the waiter hangs until something times it out. Before this hook existed,
	 *        callers avoided that by hand-rolling this method's create/associate steps and
	 *        dispatching themselves; three separate copies of that workaround existed.
	 *
	 *        A throw from $beforeDispatch propagates with NOTHING dispatched -- the batch
	 *        row and waiter record exist but no work starts. That is the safe failure
	 *        direction: the caller sees the exception and can clean up, versus jobs running
	 *        against a waiter that was never marked and can never be resumed.
	 * @return JobBatch
	 * @throws Throwable
	 */
	public static function createForJobs(string $name, array $jobs, $onComplete = null, ?Model $waiter = null, ?callable $beforeDispatch = null): JobBatch
	{
		if ($waiter && !$waiter instanceof JobBatchWaiterContract) {
			throw new InvalidArgumentException(get_class($waiter) . ' must implement JobBatchWaiterContract to be used as a JobBatch waiter');
		}

		$batchRef = ModelRef::generate('JB-');

		$onCompleteToStore = $waiter
			? static::wrapOnCompleteWithWaiterResolution($waiter, $onComplete)
			: $onComplete;

		$jobBatch = JobBatch::create([
			'name'           => "$name - $batchRef",
			'total_jobs'     => count($jobs),
			'pending_jobs'   => count($jobs),
			'failed_jobs'    => 0,
			'failed_job_ids' => '',
			'created_at'     => now()->timestamp,
			'on_complete'    => $onCompleteToStore ? serialize(new SerializableClosure($onCompleteToStore)) : null,
		]);

		if ($waiter) {
			static::addWaiter($jobBatch, $waiter);
		}

		$jobIds = [];
		foreach($jobs as $job) {
			if ($job->getJobDispatch()) {
				$jobIds[] = $job->getJobDispatch()->id;
			}
		}

		// Associate all jobs with the batch
		JobDispatch::whereIn('id', $jobIds)->update(['job_batch_id' => $jobBatch->id]);

		// Everything is associated and the waiter is registered, but nothing has been
		// dispatched yet -- the caller's own bookkeeping goes here. See the $beforeDispatch
		// docblock for the race this ordering exists to close.
		if ($beforeDispatch) {
			$beforeDispatch($jobBatch);
		}

		// Dispatch all the jobs
		foreach($jobs as $job) {
			$job->dispatch();
		}

		return $jobBatch;
	}

	/**
	 * Registers $waiter as waiting on $jobBatch. Called from createForJobs() BEFORE any of
	 * the batch's jobs are dispatched -- a job that completes synchronously must find this
	 * row already present when it settles the batch.
	 */
	public static function addWaiter(JobBatch $jobBatch, Model&JobBatchWaiterContract $waiter): JobBatchWaiterRecord
	{
		return JobBatchWaiterRecord::create([
			'job_batch_id' => $jobBatch->id,
			'waiter_type'  => get_class($waiter),
			'waiter_id'    => $waiter->getKey(),
			'resolved_at'  => null,
			'created_at'   => now(),
		]);
	}

	/**
	 * Builds the on_complete closure createForJobs() stores when a $waiter is given.
	 * Captures ONLY primitives (class name + id) -- NEVER the $waiter model instance --
	 * because on_complete is serialize()'d (SerializableClosure over a raw PHP serialize(),
	 * not SerializesModels) and may run in a completely separate process/request than the
	 * one that created it; the waiter is reloaded fresh via find() at settlement time.
	 *
	 * Runs $onComplete (if given) FIRST, unconditionally, every time this specific batch
	 * settles -- same as a plain createForJobs() call without a waiter. The
	 * waiter-resolution logic below is independent of $onComplete and always runs after it.
	 */
	public static function wrapOnCompleteWithWaiterResolution(Model&JobBatchWaiterContract $waiter, ?callable $onComplete): Closure
	{
		$waiterClass = get_class($waiter);
		$waiterId    = $waiter->getKey();

		return function (JobBatch $jobBatch) use ($waiterClass, $waiterId, $onComplete): void {
			if ($onComplete) {
				$onComplete($jobBatch);
			}

			static::resolveWaiterForJobBatch($jobBatch, $waiterClass, $waiterId);
		};
	}

	/**
	 * Called from the on_complete closure once $jobBatch (one of possibly several batches
	 * $waiterClass#$waiterId is waiting on) has settled. Marks THIS batch's row resolved
	 * and, only if every other row for the same waiter is ALSO already resolved, calls the
	 * waiter's own resumeFromJobBatch().
	 *
	 * Lock ordering is deliberate: the lock is acquired and released HERE, fully, before
	 * ever calling into $waiter->resumeFromJobBatch() -- which may itself acquire its own
	 * lock on the waiter model. LockHelper::release() is an unconditional force-release with
	 * no re-entrancy depth counter, so nesting a second acquire/release pair for the SAME key
	 * inside this one would end this method's own critical section early -- hence the lock is
	 * always fully closed out before this method calls out to anything else.
	 *
	 * The lock key is a plain string ("$waiterClass:$waiterId"), matching LockHelper::
	 * resolveKey()'s own Model-derived format exactly, so a fresh find() to build a real
	 * Model instance isn't needed just to take the lock.
	 */
	protected static function resolveWaiterForJobBatch(JobBatch $jobBatch, string $waiterClass, int|string $waiterId): void
	{
		$lockKey = "$waiterClass:$waiterId";
		LockHelper::acquire($lockKey);

		try {
			JobBatchWaiterRecord::where('job_batch_id', $jobBatch->id)
				->where('waiter_type', $waiterClass)
				->where('waiter_id', $waiterId)
				->whereNull('resolved_at')
				->update(['resolved_at' => now()]);

			$remaining = JobBatchWaiterRecord::where('waiter_type', $waiterClass)
				->where('waiter_id', $waiterId)
				->whereNull('resolved_at')
				->count();
		} finally {
			LockHelper::release($lockKey);
		}

		if ($remaining > 0) {
			return;
		}

		// Preserve withTrashed() resilience for soft-deletable waiters, without assuming
		// every waiter model uses SoftDeletes. withTrashed() is a query-builder macro
		// registered by SoftDeletingScope, not a static method or local scope on the model
		// itself -- method_exists()/scope-name checks against the class can't detect it.
		// class_uses_recursive() against the actual trait is the correct check (same
		// pattern DanxServiceProvider::registerDanxRelationCounters() already uses for
		// HasRelationCountersTrait).
		$usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($waiterClass), true);
		$query           = $usesSoftDeletes ? $waiterClass::withTrashed() : $waiterClass::query();
		$waiter          = $query->find($waiterId);

		if (!$waiter) {
			Log::warning("JobBatch waiter $waiterClass#$waiterId not found when resolving JobBatch #$jobBatch->id -- cannot resume.");

			return;
		}

		// $anyShardFailed must reflect EVERY batch this waiter was registered against, not
		// just $jobBatch (the one that happened to settle last) -- a batch that failed
		// earlier, while a sibling batch was still pending, must still be reported here.
		// Checking only $jobBatch->failed_jobs would silently report success whenever the
		// LAST-settling batch happened to be clean, even if an earlier sibling batch failed.
		$waiterBatchIds = JobBatchWaiterRecord::where('waiter_type', $waiterClass)
			->where('waiter_id', $waiterId)
			->pluck('job_batch_id');

		$anyShardFailed = JobBatch::whereIn('id', $waiterBatchIds)->where('failed_jobs', '>', 0)->exists();

		$waiter->resumeFromJobBatch($jobBatch, $anyShardFailed);
	}

	/**
	 * @return HasMany|JobDispatch[]
	 */
	public function jobDispatches()
	{
		return $this->hasMany(JobDispatch::class, 'job_batch_id');
	}

	/**
	 * @return int number of jobs successfully processed
	 */
	public function getProcessedJobsAttribute()
	{
		return $this->total_jobs - $this->pending_jobs;
	}

	/**
	 * @return float|int
	 */
	public function getProgressAttribute()
	{
		return $this->total_jobs ? round($this->processed_jobs / $this->total_jobs, 1) : 0;
	}
}
