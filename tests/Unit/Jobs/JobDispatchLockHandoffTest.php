<?php

namespace Tests\Unit\Jobs;

use Illuminate\Support\Facades\Config;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Models\Job\JobDispatch;
use Tests\TestCase;

/**
 * A Job with a caller-controlled, fixed ref (rather than the uniqid()-suffixed refs other
 * test doubles use) so two separate instances can genuinely collide on the same debounce key.
 */
class FixedRefTestJob extends Job
{
    public function __construct(private readonly string $fixedRef)
    {
        parent::__construct();
    }

    public function ref(): string
    {
        return $this->fixedRef;
    }

    public function run(): void
    {
        // no-op — these tests are about the dispatch/execute debounce lock, not job work.
    }

    protected function requiresAuth(): bool
    {
        return false;
    }
}

/**
 * Job::dispatch() takes a short-lived debounce lock on the job's ref so a burst of redundant
 * dispatch requests collapses to one Pending JobDispatch row; Job::executeJob() releases that
 * SAME lock the moment the job actually starts running, so a later, genuinely new dispatch of
 * the same ref is never needlessly blocked behind it.
 *
 * THE BUG THIS GUARDS. Before this fix, dispatch() acquired via LockHelper::get() (same-process
 * API) and executeJob() released via LockHelper::release() (same-process API) — a same-process
 * acquire/release PAIR used across what is architecturally a CROSS-PROCESS handoff (dispatch()
 * runs in the process that queues the job; executeJob() runs wherever the queue worker picks it
 * up, almost always a different process). LockHelper::release() checks ownership against its
 * own in-memory $acquiredLocks, keyed by pid — a fresh queue-worker process has an EMPTY
 * $acquiredLocks, so release() found nothing to release, logged "RELEASE-NOT-HELD", and left the
 * debounce lock held until its 30s TTL lapsed on its own. Every real dispatch of that ref inside
 * that 30s window was then wrongly treated as a duplicate and aborted (JobDispatch::STATUS_ABORTED)
 * — a silent drop of real, intended work, not merely a noisy log line.
 *
 * THE FIX carries an explicit owner token from dispatch() to executeJob() on the job object
 * itself (Job::$dispatchLockOwner) — the same mechanism that already carries $jobDispatch across
 * that boundary — and uses LockHelper::tryAcquireForHandoff()/releaseByOwner(), which check
 * ownership via the token (stored in the cache backend itself), never via which process/pid is
 * asking. These tests exercise dispatch() and executeJob() for real (via a real, synchronous
 * Job run), not a reimplementation of the locking rule.
 */
class JobDispatchLockHandoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic: dispatch() must run the job's full handle()/executeJob() path
        // synchronously, in this same test, so we can assert on the JobDispatch row it leaves.
        Config::set('queue.default', 'sync');
    }

    public function test_executing_a_job_releases_its_debounce_lock_so_a_later_dispatch_of_the_same_ref_is_not_aborted(): void
    {
        $ref = 'job-handoff-test-' . uniqid('', true);

        (new FixedRefTestJob($ref))->dispatch();

        $first = JobDispatch::where('ref', $ref)->orderByDesc('id')->first();
        $this->assertNotNull($first, 'the first dispatch must have created a JobDispatch row');
        $this->assertNotSame(
            JobDispatch::STATUS_ABORTED,
            $first->status,
            'the first dispatch of a fresh ref must never be aborted — nothing else holds this ref yet',
        );

        // The real regression: dispatching the SAME ref again, well within the debounce lock's
        // 30s TTL, immediately after the first job has already finished running (and, per the
        // fix, already released its lock). Before the fix this second dispatch would be wrongly
        // aborted, because the first job's release() call — running in what is architecturally a
        // different process — never actually cleared the lock.
        (new FixedRefTestJob($ref))->dispatch();

        $second = JobDispatch::where('ref', $ref)->orderByDesc('id')->first();
        $this->assertNotNull($second, 'the second dispatch must have created its own JobDispatch row');
        $this->assertNotEquals($first->id, $second->id, 'a NEW row is expected once the first is no longer Pending');
        $this->assertNotSame(
            JobDispatch::STATUS_ABORTED,
            $second->status,
            'a genuinely new dispatch of a ref whose prior holder already finished and released must run, not be aborted as a duplicate',
        );
    }

    public function test_a_ref_still_genuinely_held_by_another_in_flight_dispatch_is_still_correctly_aborted_as_a_duplicate(): void
    {
        $ref = 'job-handoff-collision-test-' . uniqid('', true);

        // Simulate another process's dispatch() that has taken the debounce lock and not yet
        // released it (its job has not started running) — the exact case this lock exists to
        // catch, and the one case a fix here must never break.
        $owner = LockHelper::tryAcquireForHandoff($ref, 30);
        $this->assertNotNull($owner, 'the simulated in-flight dispatch must have taken the lock cleanly');

        (new FixedRefTestJob($ref))->dispatch();

        $dispatch = JobDispatch::where('ref', $ref)->orderByDesc('id')->first();
        $this->assertNotNull($dispatch);
        $this->assertSame(
            JobDispatch::STATUS_ABORTED,
            $dispatch->status,
            'a ref genuinely still held by another dispatch must still be debounced — this fix must not weaken that guarantee',
        );
    }
}
