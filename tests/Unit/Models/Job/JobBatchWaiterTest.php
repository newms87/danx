<?php

namespace Tests\Unit\Models\Job;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Newms87\Danx\Contracts\JobBatchWaiterContract;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Models\Job\JobBatch;
use Newms87\Danx\Models\Job\JobBatchWaiterRecord;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Minimal concrete Job used purely to reach Job's private settleJobBatch() via reflection.
 * settleJobBatch() has zero dependency on $this->jobDispatch or on the job's own identity
 * (confirmed by reading its body -- it only touches the JobBatch passed to it), so any
 * concrete Job instance works as the receiver; run() is never actually invoked by these
 * tests since we call settleJobBatch() directly rather than dispatching/handling the job.
 */
class NoopJobBatchWaiterTestJob extends Job
{
    public function ref(): string
    {
        return 'noop-job-batch-waiter-test-' . uniqid('', true);
    }

    public function run()
    {
        // no-op
    }

    // These tests run unauthenticated (no team/user context) -- this job never touches
    // team-scoped data, so it's exempt from Job's default auth requirement the same way
    // other danx test jobs are (see TranscodeDataUrlToStoredFileJobTest).
    protected function requiresAuth(): bool
    {
        return false;
    }
}

/**
 * Minimal Eloquent model implementing JobBatchWaiterContract, backed by an ephemeral table
 * created in setUp() (dropped in tearDown()). Records every resumeFromJobBatch() call so
 * tests can assert exactly-once invocation and the resolved $anyShardFailed value.
 */
class TestJobBatchWaiterModel extends Model implements JobBatchWaiterContract
{
    protected $table = 'test_job_batch_waiters';

    protected $guarded = [];

    public $timestamps = false;

    public function resumeFromJobBatch(JobBatch $jobBatch, bool $anyShardFailed): void
    {
        $this->forceFill([
            'resume_call_count'         => $this->resume_call_count + 1,
            'last_resumed_job_batch_id' => $jobBatch->id,
            'last_any_shard_failed'     => $anyShardFailed,
        ])->save();
    }
}

/**
 * Covers Newms87\Danx\Models\Job\JobBatch's generic waiter mechanism: a model implementing
 * JobBatchWaiterContract registers itself (via createForJobs()'s $waiter param) as waiting
 * on one or more JobBatches, and is resumed exactly once, only after EVERY batch it
 * registered against has settled.
 *
 * Settlement is driven through the REAL, unmodified Newms87\Danx\Jobs\Job::settleJobBatch()
 * (private, reached via reflection) rather than a re-implementation of its decrement/lock/
 * on_complete-invoke logic -- this exercises the actual shipped settlement path, including
 * the real serialize()/unserialize() round-trip of the on_complete closure JobBatch stores.
 */
class JobBatchWaiterTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Schema::create('test_job_batch_waiters', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('resume_call_count')->default(0);
            $table->unsignedBigInteger('last_resumed_job_batch_id')->nullable();
            $table->boolean('last_any_shard_failed')->nullable();
        });
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('test_job_batch_waiters');

        parent::tearDown();
    }

    /**
     * Settles $jobBatch via the REAL Job::settleJobBatch(), invoked reflectively since it's
     * private. This is the exact method every real queued Job calls on success (failed:
     * false, from handle()) or terminal failure (failed: true, from failed()) -- calling it
     * directly here gives full, deterministic control over settlement timing/ordering/outcome
     * without needing to drive a real queue worker.
     */
    private function settleJobBatch(JobBatch $jobBatch, bool $failed): void
    {
        $job    = new NoopJobBatchWaiterTestJob;
        $method = new ReflectionMethod($job, 'settleJobBatch');
        $method->setAccessible(true);
        $method->invoke($job, $jobBatch, $failed);
    }

    /**
     * Creates a JobBatch registered against $waiter with zero real dispatched jobs, then
     * manually sets pending_jobs so the test can settle it exactly once via
     * $this->settleJobBatch() at a time of its own choosing. createForJobs() still runs for
     * real with $jobs = [] -- addWaiter() and the on_complete closure wrapping happen
     * unconditionally from the $waiter param, independent of $jobs content.
     */
    private function createPendingBatchForWaiter(TestJobBatchWaiterModel $waiter, string $name = 'Test Batch'): JobBatch
    {
        $jobBatch = JobBatch::createForJobs($name, [], null, $waiter);
        $jobBatch->update(['pending_jobs' => 1]);

        return $jobBatch->fresh();
    }

    public function test_resumes_waiter_after_single_batch_settles_successfully(): void
    {
        $waiter = TestJobBatchWaiterModel::create();

        $jobBatch = $this->createPendingBatchForWaiter($waiter);

        $this->settleJobBatch($jobBatch, failed: false);

        $waiter->refresh();
        $this->assertSame(1, $waiter->resume_call_count);
        $this->assertSame($jobBatch->id, $waiter->last_resumed_job_batch_id);
        $this->assertFalse((bool) $waiter->last_any_shard_failed);
    }

    public function test_does_not_resume_until_both_concurrent_batches_settle_batch_a_then_b(): void
    {
        $waiter = TestJobBatchWaiterModel::create();

        $batchA = $this->createPendingBatchForWaiter($waiter, 'Shard A');
        $batchB = $this->createPendingBatchForWaiter($waiter, 'Shard B');

        $this->assertSame(2, JobBatchWaiterRecord::where('waiter_type', TestJobBatchWaiterModel::class)
            ->where('waiter_id', $waiter->id)
            ->count());

        $this->settleJobBatch($batchA, failed: false);

        $waiter->refresh();
        $this->assertSame(0, $waiter->resume_call_count, 'Waiter must not resume while batch B is still pending');

        $this->settleJobBatch($batchB, failed: false);

        $waiter->refresh();
        $this->assertSame(1, $waiter->resume_call_count, 'Waiter must resume exactly once after the last batch settles');
        $this->assertSame($batchB->id, $waiter->last_resumed_job_batch_id);
    }

    public function test_does_not_resume_until_both_concurrent_batches_settle_batch_b_then_a(): void
    {
        $waiter = TestJobBatchWaiterModel::create();

        $batchA = $this->createPendingBatchForWaiter($waiter, 'Shard A');
        $batchB = $this->createPendingBatchForWaiter($waiter, 'Shard B');

        $this->settleJobBatch($batchB, failed: false);

        $waiter->refresh();
        $this->assertSame(0, $waiter->resume_call_count, 'Waiter must not resume while batch A is still pending');

        $this->settleJobBatch($batchA, failed: false);

        $waiter->refresh();
        $this->assertSame(1, $waiter->resume_call_count, 'Waiter must resume exactly once after the last batch settles');
        $this->assertSame($batchA->id, $waiter->last_resumed_job_batch_id);
    }

    public function test_any_shard_failed_is_true_even_when_the_failed_batch_is_not_the_last_to_settle(): void
    {
        $waiter = TestJobBatchWaiterModel::create();

        $batchA = $this->createPendingBatchForWaiter($waiter, 'Shard A');
        $batchB = $this->createPendingBatchForWaiter($waiter, 'Shard B');

        // Batch A fails first -- waiter must not resume yet (B still pending).
        $this->settleJobBatch($batchA, failed: true);

        $waiter->refresh();
        $this->assertSame(0, $waiter->resume_call_count);

        // Batch B (the LAST batch to settle) succeeds cleanly on its own -- but the waiter
        // must still be told a shard failed, because A failed, even though A wasn't last.
        $this->settleJobBatch($batchB, failed: false);

        $waiter->refresh();
        $this->assertSame(1, $waiter->resume_call_count);
        $this->assertTrue((bool) $waiter->last_any_shard_failed, 'anyShardFailed must be true because batch A failed, even though batch B (the last to settle) did not');
    }

    public function test_resolve_logs_a_warning_and_does_not_throw_when_the_waiter_no_longer_exists(): void
    {
        $waiter   = TestJobBatchWaiterModel::create();
        $jobBatch = $this->createPendingBatchForWaiter($waiter);

        $waiterId = $waiter->id;
        $waiter->delete();

        // No exception expected -- resolveWaiterForJobBatch() must degrade to a logged
        // warning when find() comes back empty, never throw and never crash the settling
        // job's own success path.
        $this->settleJobBatch($jobBatch, failed: false);
        $this->addToAssertionCount(1);

        // The batch itself must still have settled correctly despite the missing waiter.
        $jobBatch->refresh();
        $this->assertSame(0, $jobBatch->pending_jobs);
        $this->assertNotNull($jobBatch->finished_at);

        $this->assertFalse(TestJobBatchWaiterModel::where('id', $waiterId)->exists());
    }

    public function test_no_waiter_call_site_still_dispatches_all_jobs_and_settles_with_no_waiter_rows(): void
    {
        $job = new NoopJobBatchWaiterTestJob;

        $jobBatch = JobBatch::createForJobs('No Waiter Batch', [$job]);

        $jobBatch->refresh();

        // QUEUE_CONNECTION=sync in the test environment -- the single job dispatched above
        // runs synchronously inside createForJobs() and settles the batch before it returns.
        $this->assertSame(1, $jobBatch->total_jobs);
        $this->assertSame(0, $jobBatch->pending_jobs);
        $this->assertSame(0, $jobBatch->failed_jobs);
        $this->assertNotNull($jobBatch->finished_at);

        $this->assertSame(0, JobBatchWaiterRecord::where('job_batch_id', $jobBatch->id)->count());
    }
}
