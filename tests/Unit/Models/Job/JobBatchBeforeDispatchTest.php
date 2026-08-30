<?php

namespace Tests\Unit\Models\Job;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Newms87\Danx\Contracts\JobBatchWaiterContract;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Models\Job\JobBatch;
use Newms87\Danx\Models\Job\JobBatchWaiterRecord;
use RuntimeException;
use Tests\TestCase;

/**
 * Records the exact order in which JobBatch::createForJobs() performs its steps, by
 * appending a label to a shared static log from each observation point.
 */
class BeforeDispatchOrderLog
{
    /** @var array<string> */
    public static array $events = [];

    public static function reset(): void
    {
        static::$events = [];
    }

    public static function record(string $event): void
    {
        static::$events[] = $event;
    }
}

/**
 * A Job whose dispatch() is observable. Records that it was dispatched so the ordering
 * assertions can prove $beforeDispatch ran first.
 *
 * dispatch() is overridden rather than actually queueing: these tests are about the
 * ORDER of createForJobs()'s own steps, not about queue behaviour, and overriding keeps
 * the test independent of whichever queue driver the suite runs under.
 */
class OrderRecordingTestJob extends Job
{
    public function __construct(public string $label = 'job')
    {
        parent::__construct();
    }

    public function ref(): string
    {
        return 'before-dispatch-order-' . $this->label . '-' . uniqid('', true);
    }

    public function run()
    {
        // no-op
    }

    public function dispatch($now = false): static
    {
        BeforeDispatchOrderLog::record("dispatched:{$this->label}");

        return $this;
    }

    protected function requiresAuth(): bool
    {
        return false;
    }
}

/**
 * Minimal waiter backed by an ephemeral table, mirroring JobBatchWaiterTest's own fixture.
 */
class BeforeDispatchWaiterModel extends Model implements JobBatchWaiterContract
{
    protected $table = 'test_before_dispatch_waiters';

    protected $guarded = [];

    public $timestamps = false;

    public function resumeFromJobBatch(JobBatch $jobBatch, bool $anyShardFailed): void
    {
        // Not exercised here — these tests assert dispatch ordering, not resumption.
    }
}

/**
 * Covers the $beforeDispatch hook on JobBatch::createForJobs().
 *
 * WHAT IS ACTUALLY BEING PROTECTED: createForJobs() dispatches its jobs from inside its
 * own call, so a caller that does its "I am waiting on this batch" bookkeeping using the
 * returned JobBatch is racing those jobs. Under a synchronous queue driver the first job
 * can run to completion — and settle the batch — before createForJobs() has returned, at
 * which point the completion callback sees a waiter that is not yet marked as waiting,
 * declines to resume it, and the waiter hangs until something times it out.
 *
 * The hook closes that race by giving the caller a moment that is provably after the
 * batch, the waiter record and the dispatch associations exist, and provably before any
 * job runs. These tests assert exactly that ordering, because ordering is the entire
 * value of the hook — a version of this code that called $beforeDispatch after the
 * dispatch loop would pass any test that only checked "the callback ran".
 */
class JobBatchBeforeDispatchTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        BeforeDispatchOrderLog::reset();

        Schema::create('test_before_dispatch_waiters', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('test_before_dispatch_waiters');

        BeforeDispatchOrderLog::reset();

        parent::tearDown();
    }

    /**
     * The core guarantee: every job dispatch happens after the hook returns.
     */
    public function test_beforeDispatch_runs_before_any_job_is_dispatched(): void
    {
        $jobs = [
            new OrderRecordingTestJob('a'),
            new OrderRecordingTestJob('b'),
            new OrderRecordingTestJob('c'),
        ];

        JobBatch::createForJobs('OrderingTest', $jobs, beforeDispatch: function (JobBatch $jobBatch): void {
            BeforeDispatchOrderLog::record('beforeDispatch');
        });

        $this->assertEquals(
            ['beforeDispatch', 'dispatched:a', 'dispatched:b', 'dispatched:c'],
            BeforeDispatchOrderLog::$events,
            'beforeDispatch must run before every job dispatch, not merely at some point during createForJobs()',
        );
    }

    /**
     * The hook receives the real, persisted batch — not a placeholder it cannot act on.
     */
    public function test_beforeDispatch_receives_the_persisted_job_batch(): void
    {
        $captured = null;

        $jobBatch = JobBatch::createForJobs(
            'OrderingTest',
            [new OrderRecordingTestJob('a')],
            beforeDispatch: function (JobBatch $batch) use (&$captured): void {
                $captured = $batch;
            },
        );

        $this->assertNotNull($captured, 'beforeDispatch was never invoked');
        $this->assertNotNull($captured->id, 'the JobBatch must already be persisted when the hook runs');
        $this->assertEquals($jobBatch->id, $captured->id);
        $this->assertDatabaseHas('job_batches', ['id' => $captured->id]);
    }

    /**
     * A waiter's record must already exist when the hook runs — that is precisely the state
     * a caller needs in order to safely mark itself as waiting.
     */
    public function test_waiter_is_registered_before_beforeDispatch_runs(): void
    {
        $waiter          = BeforeDispatchWaiterModel::create([]);
        $waiterCountSeen = null;

        JobBatch::createForJobs(
            'OrderingTest',
            [new OrderRecordingTestJob('a')],
            waiter: $waiter,
            beforeDispatch: function (JobBatch $jobBatch) use (&$waiterCountSeen): void {
                $waiterCountSeen = JobBatchWaiterRecord::where('job_batch_id', $jobBatch->id)->count();
            },
        );

        $this->assertSame(1, $waiterCountSeen, 'the waiter record must exist by the time beforeDispatch runs');
    }

    /**
     * A throw from the hook must leave every job undispatched. This is the safe failure
     * direction: a batch with no work started is recoverable; work running against a
     * waiter that was never marked is not.
     */
    public function test_a_throwing_beforeDispatch_prevents_all_dispatches(): void
    {
        $jobs = [
            new OrderRecordingTestJob('a'),
            new OrderRecordingTestJob('b'),
        ];

        try {
            JobBatch::createForJobs('OrderingTest', $jobs, beforeDispatch: function (): void {
                throw new RuntimeException('bookkeeping failed');
            });

            $this->fail('The exception from beforeDispatch should have propagated');
        } catch (RuntimeException $exception) {
            $this->assertEquals('bookkeeping failed', $exception->getMessage());
        }

        $this->assertEmpty(
            BeforeDispatchOrderLog::$events,
            'no job may be dispatched once beforeDispatch has thrown',
        );
    }

    /**
     * Omitting the hook must behave exactly as before it existed — this parameter is
     * additive and the three pre-existing call shapes must be unaffected.
     */
    public function test_createForJobs_without_the_hook_still_dispatches_everything(): void
    {
        JobBatch::createForJobs('OrderingTest', [
            new OrderRecordingTestJob('a'),
            new OrderRecordingTestJob('b'),
        ]);

        $this->assertEquals(
            ['dispatched:a', 'dispatched:b'],
            BeforeDispatchOrderLog::$events,
        );
    }
}
