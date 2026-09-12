<?php

namespace Tests\Unit\Jobs;

use Illuminate\Support\Facades\Queue;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Models\Job\JobDispatch;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Job whose every dispatch carries the same ref, so dispatches of it debounce onto one
 * another. Counts its runs so a test can tell a delivered message that ran from one that
 * was skipped.
 */
class DelayedPendingDispatchTestJob extends Job
{
    public static int $runs = 0;

    public function __construct(public string $key)
    {
        parent::__construct();
    }

    public function ref(): string
    {
        return 'delayed-pending-dispatch-test:' . $this->key;
    }

    public function run(): void
    {
        static::$runs++;
    }

    protected function requiresAuth(): bool
    {
        return false;
    }
}

/**
 * Debouncing onto a Pending dispatch may only ever make a dispatch run SOONER than it asked.
 *
 * Job::dispatch() folds a dispatch into the ref's existing Pending row instead of queueing a
 * second message. When that row's message was queued with a delay, folding a dispatch that
 * asked to run now made it wait out someone else's delay. Production, gpt-manager SG-495
 * (2026-09-11): a TaskOrchestratorJob was queued with a 600 s delay to back off one worker's
 * retry; another worker completed five minutes later, its "run the orchestrator" dispatch
 * folded into that delayed row (JobDispatch 10087, count 2), and the whole run sat idle until
 * the delay expired. JobDispatch 9842 absorbed 13 such dispatches the same way.
 *
 * The queue is Queue::fake()d on a non-sync connection so dispatches stay Pending, and each
 * pushed message is "delivered" by a serialize/unserialize round trip into handle() — the
 * same path a queue worker takes.
 */
class DelayedPendingDispatchTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'redis']);
        Queue::fake();
        DelayedPendingDispatchTestJob::$runs = 0;
    }

    /** Run a queued message the way a queue worker does: rebuilt from its serialized payload. */
    private function deliver(DelayedPendingDispatchTestJob $message): void
    {
        unserialize(serialize($message))->handle();
    }

    /** @return DelayedPendingDispatchTestJob[] every message pushed onto the queue, in push order */
    private function pushedMessages(): array
    {
        return Queue::pushed(DelayedPendingDispatchTestJob::class)->values()->all();
    }

    #[Test]
    public function a_dispatch_that_finds_its_ref_pending_behind_a_delay_runs_now_rather_than_waiting_out_the_delay(): void
    {
        $delayed = (new DelayedPendingDispatchTestJob('sg495'))->delay(600)->dispatch();
        $held    = $delayed->getJobDispatch();
        $this->assertSame(JobDispatch::STATUS_PENDING, $held->status, 'Pre-condition: the delayed dispatch is Pending');

        $prompt = (new DelayedPendingDispatchTestJob('sg495'))->dispatch();

        $replacement = $prompt->getJobDispatch();
        $this->assertNotSame($held->id, $replacement->id, 'A dispatch asking to run now must not fold into a row held back 600 s');
        $this->assertSame(JobDispatch::STATUS_PENDING, $replacement->fresh()->status);
        $this->assertSame(JobDispatch::STATUS_ABORTED, $held->fresh()->status, 'The delayed row is superseded, so its message never runs');
        $this->assertSame($held->count + 1, $replacement->count, 'The replacement carries the debounce count of the dispatches it absorbed');

        $messages = $this->pushedMessages();
        $this->assertCount(2, $messages, 'The replacement is queued as a message of its own');
        $this->assertSame(600, $messages[0]->delay);
        $this->assertEmpty($messages[1]->delay, 'The replacement is queued with no delay');
        $this->assertSame($replacement->id, $messages[1]->getJobDispatch()->id);

        // The replacement runs as soon as it is delivered.
        $this->deliver($messages[1]);
        $this->assertSame(1, DelayedPendingDispatchTestJob::$runs);
        $this->assertSame(JobDispatch::STATUS_COMPLETE, $replacement->fresh()->status);

        // Ten minutes later the superseded message arrives, and does nothing.
        $this->deliver($messages[0]);
        $this->assertSame(1, DelayedPendingDispatchTestJob::$runs, 'A superseded dispatch must never run');
        $this->assertSame(JobDispatch::STATUS_ABORTED, $held->fresh()->status);
    }

    #[Test]
    public function a_dispatch_still_folds_onto_a_pending_row_that_runs_no_later_than_it_asked(): void
    {
        $first  = (new DelayedPendingDispatchTestJob('debounce'))->dispatch();
        $second = (new DelayedPendingDispatchTestJob('debounce'))->dispatch();
        $third  = (new DelayedPendingDispatchTestJob('debounce'))->delay(600)->dispatch();

        $row = $first->getJobDispatch();
        $this->assertSame($row->id, $second->getJobDispatch()->id, 'Two prompt dispatches debounce into one row');
        $this->assertSame($row->id, $third->getJobDispatch()->id, 'A delayed dispatch folds into a row that runs sooner than it asked');
        $this->assertSame(3, $row->fresh()->count);
        $this->assertSame(JobDispatch::STATUS_PENDING, $row->fresh()->status);
        $this->assertCount(1, $this->pushedMessages(), 'Debounced dispatches queue no further message');
    }

    #[Test]
    public function a_shorter_delay_supersedes_a_longer_one_and_keeps_its_own_delay(): void
    {
        $long  = (new DelayedPendingDispatchTestJob('shorter'))->delay(600)->dispatch();
        $short = (new DelayedPendingDispatchTestJob('shorter'))->delay(30)->dispatch();

        $this->assertNotSame($long->getJobDispatch()->id, $short->getJobDispatch()->id);
        $this->assertSame(JobDispatch::STATUS_ABORTED, $long->getJobDispatch()->fresh()->status);

        $messages = $this->pushedMessages();
        $this->assertCount(2, $messages);
        $this->assertSame(30, $messages[1]->delay, 'The superseding dispatch keeps the delay IT asked for');
    }
}
