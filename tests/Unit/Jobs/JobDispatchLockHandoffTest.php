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
 * A Job with a fixed ref that ALSO opts out of the debounce/handoff lock entirely (SG-814's
 * Job::debouncesDispatch() === false) — the shape of a job whose ref() is unique per dispatch
 * by design (e.g. App\Jobs\TaskWorkerJob), simulated here with a fixed ref so the test can
 * force a genuine lock collision and confirm it is correctly ignored.
 *
 * Deliberately extends Job directly rather than FixedRefTestJob: Job::__unserialize() mangles
 * a PRIVATE property's serialized key via get_class($this) (the runtime class) rather than the
 * property's declaring class, so a subclass of FixedRefTestJob loses its inherited $fixedRef on
 * the queue's real serialize/unserialize round-trip. That is a separate, pre-existing bug in
 * the unmangling logic, not something this card's fix causes or needs to touch — this test
 * double sidesteps it by declaring $fixedRef itself instead of inheriting it.
 */
class NonDebouncingFixedRefTestJob extends Job
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

    protected function debouncesDispatch(): bool
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
    public function setUp(): void
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

    /**
     * SG-814: TaskWorkerJob's ref() is a uniqid()-suffixed token by design, specifically so
     * concurrent workers are never folded together — which means it can never collide with
     * anything, ever. Before this fix, Job::dispatch() still unconditionally took the
     * debounce lock anyway, which bought zero protection (nobody else could ever compute the
     * same ref) and cost a guaranteed RELEASE-BY-OWNER-FAILED once queue latency outran the
     * lock's fixed 30s TTL — measured at 100/110 (91%) on a real local-dev run. This test
     * proves the opt-out actually skips the lock, using a FORCED collision (a ref pre-locked
     * by a simulated in-flight dispatch, exactly like the sibling collision test above) that a
     * debouncing job would correctly abort on — a non-debouncing job must run anyway, because
     * it must never even check.
     */
    public function test_a_job_that_opts_out_of_debouncing_ignores_a_ref_even_when_another_dispatch_already_holds_its_lock(): void
    {
        $ref = 'job-no-debounce-collision-test-' . uniqid('', true);

        $owner = LockHelper::tryAcquireForHandoff($ref, 30);
        $this->assertNotNull($owner, 'the simulated in-flight dispatch must have taken the lock cleanly');

        (new NonDebouncingFixedRefTestJob($ref))->dispatch();

        $dispatch = JobDispatch::where('ref', $ref)->orderByDesc('id')->first();
        $this->assertNotNull($dispatch);
        $this->assertNotSame(
            JobDispatch::STATUS_ABORTED,
            $dispatch->status,
            'a job that opts out of debouncing must run regardless of another dispatch holding the same ref\'s debounce lock',
        );
    }

    /**
     * The direct mechanism check: debouncesDispatch() === false must mean dispatch() never
     * even calls LockHelper::tryAcquireForHandoff() — not merely that the call happens to
     * succeed. $dispatchLockOwner staying null is exactly what makes executeJob() skip the
     * release attempt too (see its own `if ($this->dispatchLockOwner !== null)` guard), which
     * is what eliminates the RELEASE-BY-OWNER-FAILED noise at the source rather than masking it.
     */
    public function test_a_job_that_opts_out_of_debouncing_never_acquires_the_handoff_lock(): void
    {
        $ref = 'job-no-debounce-mechanism-test-' . uniqid('', true);

        $job = new NonDebouncingFixedRefTestJob($ref);
        $job->dispatch();

        $property = new \ReflectionProperty(Job::class, 'dispatchLockOwner');
        $property->setAccessible(true);

        $this->assertNull(
            $property->getValue($job),
            'a non-debouncing job must never populate dispatchLockOwner — dispatch() must skip tryAcquireForHandoff() entirely',
        );

        $dispatch = JobDispatch::where('ref', $ref)->orderByDesc('id')->first();
        $this->assertNotNull($dispatch);
        $this->assertSame(JobDispatch::STATUS_COMPLETE, $dispatch->status);
    }
}
