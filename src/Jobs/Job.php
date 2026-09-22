<?php

namespace Newms87\Danx\Jobs;

use Carbon\Carbon;
use DateInterval;
use DateTimeInterface;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Helpers\DateHelper;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Models\Job\JobBatch;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Support\Heartbeat;
use Newms87\Danx\Traits\HasDebugLogging;
use ReflectionClass;
use Throwable;

abstract class Job implements ShouldQueue
{
    use Batchable, HasDebugLogging, InteractsWithQueue, Queueable, SerializesModels;

    protected ?JobDispatch $jobDispatch = null;

    /**
     * The owner token {@see dispatch()} mints when it takes the debounce lock on
     * {@see JobDispatch::$ref} — carried across the dispatch -> execute process boundary on this
     * SAME serialized job object (the same mechanism that already carries `$jobDispatch` there),
     * and consumed by {@see executeJob()} to release that SAME lock via
     * {@see LockHelper::releaseByOwner()}. See the "CROSS-PROCESS HANDOFF" section of
     * {@see LockHelper}'s own docblock: the process that acquires this debounce lock is never the
     * process that releases it, so a same-process `get()`/`release()` pair is the wrong primitive
     * here — that pairing silently stopped releasing anything the moment `release()` became
     * owner-checked, since the executing process's own `$acquiredLocks` never held it.
     */
    protected ?string $dispatchLockOwner = null;

    /**
     * Whether this job's dispatch() takes the ref-based debounce/handoff lock at all (the
     * lock {@see $dispatchLockOwner} tracks). Default true: most jobs' {@see ref()} names a
     * real, REUSABLE resource (e.g. "task-orchestrator:344"), so two dispatches racing for
     * that same resource genuinely need the lock to fold/serialise correctly.
     *
     * Override to false for a job whose ref() is unique PER DISPATCH — deliberately carries
     * a uniqid()/microtime()-style token specifically so concurrent dispatches are never
     * folded together (see {@see \App\Jobs\TaskWorkerJob::ref()} in the consuming gpt-manager
     * app for the canonical example). For such a job the debounce lock can never structurally
     * see a real collision: nobody else could ever compute the same ref, so nobody could ever
     * be genuinely waiting on it. Acquiring it anyway buys nothing and costs a GUARANTEED
     * RELEASE-BY-OWNER-FAILED the moment real queue latency (the time between dispatch()
     * enqueuing the message and a worker actually reaching executeJob()) exceeds the lock's
     * fixed TTL — which a queue-depth burst routinely does, since the TTL is fixed at 30s
     * while queue wait time is unbounded.
     *
     * Measured (gpt-manager SG-814, local dev, workflow-61): TaskWorkerJob's per-dispatch
     * debounce lock failed to release 100/110 times (91%) because ran_at trailed created_at
     * by a median of 404s, far past the 30s TTL — while 0 of 1433 historical TaskWorkerJob
     * dispatches ever actually needed debouncing (0 Aborted, 0 rows folded to count>1, 0
     * duplicate refs — impossible by construction once the ref carries a uniqid()). The lock
     * that actually serialises overlapping WORK on that path is a completely separate,
     * same-process resource lock (LockHelper::acquire()/release() on the WorkflowRun /
     * TaskOrchestrator / TaskWorker models themselves) — untouched by this flag, and verified
     * to balance ACQUIRED/RELEASED perfectly across the same run.
     */
    protected function debouncesDispatch(): bool
    {
        return true;
    }

    /**
     * Whoever was authenticated in THIS process before __unserialize() logged in as this job's
     * own user/team — captured there, consumed by restoreCallerAuthContext() (SG-494).
     *
     * Only meaningful for a job that ran SYNCHRONOUSLY (see restoreCallerAuthContext()): a real
     * queue worker process has no caller of its own, so this is captured unconditionally
     * (a cheap property read) but only ever acted on for a SyncJob.
     */
    private $callerAuthUser = null;

    /**
     * Whether __unserialize() has run and captured $callerAuthUser for THIS instance. Needed
     * because "no one was authenticated" and "not captured yet" are both represented by a null
     * $callerAuthUser, and only the former should trigger a logout on restore.
     */
    private bool $callerAuthCaptured = false;

    // Log out previous authenticated user before running the job
    // NOTE: This is disabled during testing, so we can run jobs as the authenticated user
    public static $logoutUser = true;

    // The list of Jobs that have been flagged as disabled and will not be executed
    protected static $disabledJobs = [];

    // A flag to indicate a Job is running in this instance of the application
    public static $isRunning  = false;

    public static ?JobDispatch $runningJob = null;

    /**
     * Class constructor.
     *
     * @throws Exception
     * @throws Throwable
     */
    public function __construct()
    {
        if (!static::isDisabled()) {
            $this->resolveJobDispatch();
        }
    }

    /**
     * @return JobDispatch|null
     */
    public function getJobDispatch()
    {
        return $this->jobDispatch;
    }

    /**
     * @return void
     *
     * @throws Exception
     * @throws Throwable
     */
    public function resolveJobDispatch()
    {
        $ref = $this->ref();

        try {
            LockHelper::acquire('resolve-' . $ref);
        } catch (Throwable $exception) {
            static::logDebug("Lock acquisition skipped: Job was recently triggered: $ref");
            $this->jobDispatch = JobDispatch::where('ref', $ref)->orderByDesc('id')->first();

            return;
        }

        try {
            $jobDispatch = JobDispatch::firstOrNew([
                'ref'    => $ref,
                'status' => JobDispatch::STATUS_PENDING,
            ]);

            if ($jobDispatch->isTimedOut()) {
                $jobDispatch->update(['status' => JobDispatch::STATUS_TIMEOUT]);

                $jobDispatch = JobDispatch::make([
                    'ref'    => $ref,
                    'status' => JobDispatch::STATUS_PENDING,
                ]);
            }

            if (!$jobDispatch->exists) {
                $jobDispatch = $this->createPendingDispatch($ref, 1, null);
            }

            if (config('danx.audit.enabled')) {
                $jobDispatch->update(['dispatch_audit_request_id' => AuditDriver::getAuditRequest()?->id]);
            }

            if (config('queue.debug')) {
                static::logDebug("Created $jobDispatch");
            }

            $this->jobDispatch = $jobDispatch;
        } finally {
            LockHelper::release('resolve-' . $ref);
        }
    }

    /**
     * Insert the Pending row a new dispatch of $ref runs as. Called only while holding the
     * `resolve-<ref>` lock, which serialises every resolution of a ref.
     */
    private function createPendingDispatch(string $ref, int $count, ?string $jobBatchId): JobDispatch
    {
        $jobDispatch = JobDispatch::make([
            'ref'    => $ref,
            'status' => JobDispatch::STATUS_PENDING,
        ]);

        $jobDispatch->forceFill([
            'user_id'         => user()?->id ?: null,
            'name'            => class_basename(static::class),
            'count'           => $count,
            'job_batch_id'    => $jobBatchId,
            'will_timeout_at' => $this->getTimeoutAt(),
        ])->save();

        return $jobDispatch;
    }

    /**
     * When this dispatch's message becomes deliverable: now, or the end of its ->delay().
     */
    public function availableAt(): Carbon
    {
        return match (true) {
            $this->delay instanceof DateTimeInterface => Carbon::instance($this->delay)->max(now()),
            $this->delay instanceof DateInterval      => now()->add($this->delay),
            default                                   => now()->addSeconds((int)($this->delay ?? 0)),
        };
    }

    /**
     * Get the timeout datetime based on when the job will be evicted / retried by laravel's job runner
     */
    public function getTimeoutAt(): Carbon
    {
        $connection = collect(config('queue.connections'))->where('queue', $this->queue ?: 'default')->first();

        return now()->addSeconds($this->timeout ?? $connection['retry_after'] ?? 90);
    }

    /**
     * Dispatches the job while debouncing on enforcing unique jobs based on the ref
     *
     * NOTE: Debouncing works by using the Job's ref as a unique identifier. If a Job is dispatched while a duplicate
     * job is still Pending, it will be considered a duplicate and will not be executed. However, if a job is
     * dispatched while a duplicate job is Running, we will allow it to run as it is possible changes have been made
     * since the job started running.
     *
     * INVARIANT: a dispatch never runs later than it asked to. Folding into the Pending row is allowed only when
     * that row's message becomes deliverable no later than this dispatch's own would. A Pending row held back
     * longer — queued with a ->delay() — is superseded instead (see supersedePendingDispatch()). Before this, a
     * dispatch asking to run now inherited whatever delay the Pending row was queued with (gpt-manager SG-495:
     * 13 worker completions folded into one TaskOrchestratorJob queued 600 s out, and the run sat idle).
     */
    public function dispatch($now = false): static
    {
        // Don't do anything if Job dispatching is disabled
        if (!$this->jobDispatch) {
            static::logDebug('Job Dispatch is disabled');

            return $this;
        }

        // If the Job was recently created, then it is the first time it has been dispatched
        if ($this->jobDispatch->wasRecentlyCreated) {
            // If we cannot immediately acquire the lock, that means someone else is already doing what we're trying to do
            // This will be released when the job is just about to execute, we are debouncing all other redundant requests.
            // A cross-process handoff (this process acquires; the queue worker that later executes the job
            // releases) — never same-process get()/release(), which only LOOKS like it works because the two
            // sides share no memory to check ownership against.
            //
            // Skipped entirely when debouncesDispatch() is false — see that method's docblock
            // (gpt-manager SG-814): a job whose ref() can never collide gets zero protective
            // value from this lock and only a guaranteed eventual RELEASE-BY-OWNER-FAILED once
            // queue latency outruns the lock's fixed TTL.
            if ($this->debouncesDispatch()) {
                $this->dispatchLockOwner = LockHelper::tryAcquireForHandoff($this->jobDispatch->ref, 30);

                if ($this->dispatchLockOwner === null) {
                    static::logDebug("Job {$this->jobDispatch->ref} is already running");
                    $this->jobDispatch->update(['status' => JobDispatch::STATUS_ABORTED]);

                    return $this;
                }
            }

            $this->send($now);
        } elseif ($this->isHeldBackLongerThanAsked($this->jobDispatch, $now)) {
            $this->supersedePendingDispatch($now);
        } else {
            // Increment the counter to indicate the number of debounced jobs
            $this->jobDispatch->update(['count' => $this->jobDispatch->count + 1]);

            if (config('queue.debug')) {
                static::logDebug("Pending Job {$this->jobDispatch->id} still waiting [count: {$this->jobDispatch->count}]");
            }
        }

        return $this;
    }

    /**
     * Run this dispatch's row: inline when it must run now (or the queue is sync), otherwise queue its message,
     * stamping when that message becomes deliverable.
     */
    private function send(bool $now): void
    {
        $dispatcher = app(Dispatcher::class);

        // If this job is not supposed to be added to the Job Queue, then we want to dispatch it immediately
        if ($now || config('queue.default') === 'sync') {
            $dispatcher->dispatchSync($this);
        } else {
            $this->jobDispatch->update([
                'available_at'    => $this->availableAt(),
                'will_timeout_at' => $this->getTimeoutAt(),
            ]);
            $dispatcher->dispatch($this->job ?: $this);
        }
    }

    /**
     * Whether $jobDispatch is a Pending row whose message becomes deliverable later than this dispatch asks to run.
     * A row with no recorded available_at (never queued) holds nothing back.
     */
    private function isHeldBackLongerThanAsked(JobDispatch $jobDispatch, bool $now): bool
    {
        if ($jobDispatch->status !== JobDispatch::STATUS_PENDING || !$jobDispatch->available_at) {
            return false;
        }

        return $jobDispatch->available_at->greaterThan($now ? now() : $this->availableAt());
    }

    /**
     * Replace the ref's Pending row, held back by a delay longer than this dispatch asked for, with a fresh Pending
     * row sent on this dispatch's own timing. The held-back row is Aborted, so its message is skipped when it is
     * eventually delivered (see handle()). The replacement carries the debounce count and JobBatch membership.
     *
     * Runs under the `resolve-<ref>` lock that serialises every resolution of the ref, re-reading the row first: a
     * concurrent dispatch may have superseded or started it since this job resolved it. When it no longer holds
     * anything back, this dispatch resolves again against whatever row now holds the ref.
     */
    private function supersedePendingDispatch(bool $now): void
    {
        $ref      = $this->jobDispatch->ref;
        $heldBack = $this->jobDispatch;

        LockHelper::acquire('resolve-' . $ref);

        try {
            $heldBack->refresh();

            if ($this->isHeldBackLongerThanAsked($heldBack, $now)) {
                $heldBack->update(['status' => JobDispatch::STATUS_ABORTED]);
                $this->jobDispatch = $this->createPendingDispatch($ref, $heldBack->count + 1, $heldBack->job_batch_id);

                if (config('danx.audit.enabled')) {
                    $this->jobDispatch->update(['dispatch_audit_request_id' => AuditDriver::getAuditRequest()?->id]);
                }
            }
        } finally {
            LockHelper::release('resolve-' . $ref);
        }

        if ($this->jobDispatch === $heldBack) {
            $this->resolveJobDispatch();
            $this->dispatch($now);

            return;
        }

        static::logDebug("Superseded $heldBack, held back until {$heldBack->available_at}, with $this->jobDispatch");

        $this->send($now);
    }

    /**
     * The unique identifier for the job. Multiple jobs with the same ref will be debounced and never run multiple at
     * the same time
     */
    abstract public function ref(): string;

    /**
     * Restore the model after serialization.
     *
     * @return void
     *
     * @throws Exception
     */
    public function __unserialize(array $values)
    {
        // Reset the audit request as we want to treat each job as a new request
        AuditDriver::releaseAuditRequest();
        AuditDriver::startTimer();
        // Let other parts of the system know we're running inside a Job
        self::$isRunning = true;

        // Load the jobDispatch record immediately
        foreach ($values as $value) {
            if ($value instanceof ModelIdentifier) {
                if ($value->class === JobDispatch::class) {
                    $this->jobDispatch = JobDispatch::find($value->id);
                    self::$runningJob  = $this->jobDispatch;
                    break;
                }
            }
        }

        // Capture whoever was authenticated in THIS process BEFORE we log in as the job's own
        // user/team below, so a job that runs SYNCHRONOUSLY (inline, in the caller's own
        // request/command process) can hand control back when it finishes. See
        // restoreCallerAuthContext() -- the actual restore only happens for a SyncJob; this
        // capture is unconditional (cheap) since __unserialize() runs exactly once, before we
        // know yet whether $this->job will turn out to be a SyncJob or a real queue delivery.
        $this->callerAuthUser     = Auth::guard()->user();
        $this->callerAuthCaptured = true;

        // Set up user/team context BEFORE creating AuditRequest
        // This ensures team() returns the correct team when AuditRequest is created
        $user = $this->jobDispatch?->user()->first();
        if ($user) {
            Auth::guard()->setUser($user);
            // Set the team context from the job dispatch
            if ($this->jobDispatch->team_id) {
                $user->currentTeam = team($this->jobDispatch->team_id);
            }
        } elseif (self::$logoutUser) {
            // Be sure to log out any previous user in case the same Job Runner instance had been authenticated
            Auth::guard()->forgetUser();
        }

        // NOW create/get AuditRequest (team() will work correctly)
        AuditDriver::$auditRequest = $this->jobDispatch?->runningAuditRequest ?? AuditDriver::getAuditRequest();

        // Associate the Job dispatch to the running audit request, and set parent_id
        // to the dispatcher's audit request for direct hierarchy traversal
        if (AuditDriver::$auditRequest) {
            $this->jobDispatch?->update(['running_audit_request_id' => AuditDriver::$auditRequest->id]);

            if ($this->jobDispatch?->dispatch_audit_request_id && !AuditDriver::$auditRequest->parent_id) {
                AuditDriver::$auditRequest->update(['parent_id' => $this->jobDispatch->dispatch_audit_request_id]);
            }
        }

        $properties = (new ReflectionClass($this))->getProperties();

        $class = get_class($this);

        if (config('danx.audit.jobs.debug')) {
            static::logDebug("Unserializing Job ({$this->jobDispatch?->id})");
        }

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            // We should already have the job dispatch loaded
            if ($name === 'jobDispatch') {
                continue;
            }

            // Already captured above, from THIS process's live auth state -- must never be
            // overwritten by the value serialized at dispatch time, which is always the
            // property's unset default (null / false), since capture only happens here in
            // __unserialize(), long after the job object was serialized for the queue.
            if ($name === 'callerAuthUser' || $name === 'callerAuthCaptured') {
                continue;
            }

            if ($property->isPrivate()) {
                $name = "\0{$class}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }

            if (!array_key_exists($name, $values)) {
                continue;
            }

            try {
                if (config('danx.audit.jobs.debug')) {
                    static::logDebug("$name: " . substr(json_encode($values[$name], JSON_PRETTY_PRINT), 0, 500));
                }
                // Unnecessary overhead to load all the relationships up front - let them be lazy loaded
                unset($values[$name]->relations);
            } catch (Throwable $e) {
                // fail silently
            }

            /** @noinspection PhpExpressionResultUnusedInspection */
            $property->setAccessible(true);

            $property->setValue(
                $this, $this->getRestoredPropertyValue($values[$name])
            );
        }

        AuditDriver::$auditRequest?->update(['request' => $values]);
    }

    /**
     * Hand auth back to whoever was authenticated before __unserialize() logged in as this job's
     * own user/team (SG-494).
     *
     * Only takes effect for a job that ran SYNCHRONOUSLY -- inline, in an existing
     * request/command/test process -- which today means $this->job resolved to a real
     * Illuminate\Queue\Jobs\SyncJob (danx's own dispatchSync() path in send(), or any dispatch on
     * the `sync` queue connection). $this->job is set by Illuminate\Queue\CallQueuedHandler
     * BEFORE handle() runs, so it correctly reflects the underlying queue driver by the time this
     * is called. That is the only case where "the caller" is a real, meaningful identity that the
     * rest of the SAME process must not silently lose -- see the class-level incident this fixes.
     *
     * A real queue worker process (Redis/SQS/database -- anything but SyncJob) is deliberately
     * left untouched: it is a dedicated loop with no caller of its own to hand back to, and every
     * subsequent job's own __unserialize() overwrites the guard again regardless, so leaving the
     * job's own user set between jobs there is the existing, correct behavior.
     */
    private function restoreCallerAuthContext(): void
    {
        if (!$this->callerAuthCaptured || !($this->job instanceof SyncJob)) {
            return;
        }

        if ($this->callerAuthUser) {
            Auth::guard()->setUser($this->callerAuthUser);
        } else {
            Auth::guard()->forgetUser();
        }
    }

    /**
     * Globally enable jobs of the static class
     *
     * @return void
     */
    public static function enable()
    {
        unset(Job::$disabledJobs[static::class]);
    }

    /**
     * Globally disable jobs of the static class
     *
     * @return void
     */
    public static function disable()
    {
        Job::$disabledJobs[static::class] = true;
    }

    /**
     * Re-enable all jobs
     *
     * @return void
     */
    public static function enableAll()
    {
        Job::$disabledJobs = [];
    }

    /**
     * Disable all Jobs
     *
     * @return void
     */
    public static function disableAll()
    {
        $jobs              = FileHelper::getClassNamesInAppDir('Jobs');
        Job::$disabledJobs = array_combine($jobs, $jobs);
    }

    /**
     * Checks if the job of the static class is disabled
     *
     * @return bool
     */
    public static function isDisabled()
    {
        return !empty(Job::$disabledJobs[static::class]);
    }

    /**
     * @return void
     *
     * @throws Throwable
     */
    public function handle()
    {
        // Only run if this Job has a dispatch record
        if (!$this->jobDispatch) {
            return;
        }

        DateHelper::timerReset(static::class);

        $ref         = $this->ref();
        $prefix      = '######';
        $jobName     = "({$this->jobDispatch->id}) --- $ref";
        $traceStatus = Cache::get('debug:trace_enabled') ? 'TRACE' : 'DEBUG';
        static::logDebug("$prefix Handling  $jobName (log level: $traceStatus)");

        $jobBatch = $this->jobDispatch->jobBatch;

        try {
            // An Aborted dispatch never runs. A message can still arrive for one: dispatch() aborts a Pending row
            // held back by a delay when a later dispatch supersedes it, and the row's delayed message is delivered
            // afterwards. The replacement row does the work, and settles any JobBatch this row belonged to.
            if ($this->jobDispatch->status === JobDispatch::STATUS_ABORTED) {
                static::logDebug("$prefix Skipped   $jobName --- aborted before it ran");

                return;
            }

            $this->executeJob();
            if ($jobBatch) {
                $this->settleJobBatch($jobBatch, failed: false);
            }

            $time = DateHelper::timerStr(static::class);
            static::logDebug("$prefix Completed $jobName --- ($time)");
        } catch (Throwable $exception) {
            $time = DateHelper::timerStr(static::class);
            static::logDebug("$prefix Failed   $jobName --- ($time)");

            // Do NOT settle the JobBatch here. This catch runs on every failed attempt,
            // not just the terminal one -- a retryable job ($tries > 1, or an
            // infrastructure-level redelivery such as an SQS visibility-timeout requeue)
            // reuses the SAME JobDispatch row across attempts (see __unserialize()
            // resolving by find($id)), so settling on a non-terminal attempt here would
            // double-settle if a later attempt succeeds: pending_jobs decremented twice,
            // failed_jobs permanently overcounted for a job that ultimately succeeds, and
            // on_complete potentially fired while the job was still mid-retry. Rethrowing
            // lets Laravel's own retry/failed machinery decide whether this attempt was
            // terminal -- only failed() (called by Laravel exactly once, when retries are
            // exhausted or the job is explicitly failed) settles a genuine terminal failure.
            throw $exception;
        } finally {
            // Signal that this audit request is complete (Jobs run in app instances that do not terminate after every job)
            AuditDriver::terminate();

            // Reset the running job reference so subsequent code doesn't think we're still in a job
            self::$runningJob = null;

            // Hand auth back to the caller (sync execution only) -- must run AFTER $runningJob is
            // cleared above, since the team() helper special-cases a still-set $runningJob (see
            // its docblock) and would otherwise re-pin the restored caller onto this job's team.
            $this->restoreCallerAuthContext();
        }
    }

    /**
     * Handle a job failure (called by Laravel exactly once, when the job's retries are
     * exhausted or it is explicitly marked failed -- never on a non-terminal attempt).
     *
     * This is the SOLE place that settles a JobBatch member as failed. handle()'s own
     * catch block intentionally does NOT settle (see the comment there): it runs on every
     * failed attempt, including ones Laravel is about to retry, so settling from it would
     * fire on_complete (and overcount failed_jobs) for jobs that go on to succeed on a
     * later attempt. failed() is Laravel's own terminal-failure lifecycle hook -- called
     * whether the job died from an in-process exception or an external SIGTERM/timeout --
     * so it is always safe to settle unconditionally here.
     */
    public function failed(?Throwable $exception = null): void
    {
        $elapsed = DateHelper::timerStr(static::class);
        static::logDebug('failed()', [
            'dispatch_id'  => $this->jobDispatch?->id,
            'is_timed_out' => $this->jobDispatch?->isTimedOut(),
            'elapsed'      => $elapsed,
            'exception'    => $exception ? substr($exception->getMessage(), 0, 500) : null,
        ]);

        $jobBatch = $this->jobDispatch?->jobBatch;

        if ($this->jobDispatch) {
            if ($this->jobDispatch->isTimedOut()) {
                $this->jobDispatch->timeout();
            } else {
                $this->jobDispatch->update([
                    'status'       => JobDispatch::STATUS_FAILED,
                    'completed_at' => now(),
                ]);
            }
        }

        if ($jobBatch) {
            $this->settleJobBatch($jobBatch, failed: true);
        }
    }

    /**
     * Settle a JobBatch member's terminal state. Always decrements pending_jobs by one;
     * optionally bumps failed_jobs; fires on_complete when pending_jobs hits zero.
     *
     * Centralised so success / in-process exception / external-timeout paths share one
     * accountant. Bug history: before this method, only the success arm decremented
     * pending_jobs, so any failed/timed-out batch member left pending_jobs > 0 forever
     * and the batch's on_complete callback never ran.
     */
    private function settleJobBatch(JobBatch $jobBatch, bool $failed): void
    {
        $isFinished = false;

        LockHelper::acquire($jobBatch);
        try {
            $jobBatch->refresh();
            $jobBatch->pending_jobs = max(0, $jobBatch->pending_jobs - 1);
            if ($failed) {
                $jobBatch->failed_jobs += 1;
            }

            if ($jobBatch->pending_jobs === 0 && !$jobBatch->finished_at) {
                $jobBatch->finished_at = now()->timestamp;
                $isFinished            = true;
            }

            $jobBatch->save();
        } finally {
            LockHelper::release($jobBatch);
        }

        if ($isFinished && $jobBatch->on_complete) {
            try {
                $onComplete = unserialize($jobBatch->on_complete);

                if (is_callable($onComplete)) {
                    $onComplete($jobBatch);
                } else {
                    static::logError("on_complete callback for JobBatch is not callable $jobBatch->id");
                }
            } catch (Throwable $exception) {
                static::logError("Error executing on_complete callback for JobBatch $jobBatch->id: " . $exception->getMessage());
            }
        }
    }

    /**
     * Execute the Job and update the job status accordingly
     */
    public function executeJob()
    {
        // The method to call to run the job
        $callback = [$this, 'run'];

        Job::$isRunning = true;

        while ($runningJob = JobDispatch::runningJob($this->jobDispatch->ref)) {
            if (!$runningJob->ran_at || $runningJob->isTimedOut()) {
                $runningJob->update(['status' => JobDispatch::STATUS_TIMEOUT]);
                static::logWarning("The previously running job $runningJob timed out. It has been flagged as timed out and continuing to run the current job");
                break;
            } else {
                if (config('queue.debug')) {
                    static::logDebug("$this->jobDispatch waiting for currently running job $runningJob to complete");
                }
                sleep(5);
            }
        }

        // Release the debounce lock when we are about to execute the job, so other jobs can stack up.
        // Anything attempting to run the same job is redundant before this point. By owner, not
        // same-process release(): this call very often runs in a DIFFERENT process than dispatch()'s
        // (a queue worker picked this job up), carrying the owner token dispatch() minted via
        // tryAcquireForHandoff() on this same serialized job object (see $dispatchLockOwner).
        if ($this->dispatchLockOwner !== null) {
            LockHelper::releaseByOwner($this->jobDispatch->ref, $this->dispatchLockOwner);
        }

        // Run the Job and timestamp the run time
        $this->jobDispatch->update([
            'status' => JobDispatch::STATUS_RUNNING,
            'ran_at' => now(),
        ]);

        // Start heartbeat monitoring for the job execution
        $heartbeat = Heartbeat::start("Job:{$this->jobDispatch->name}|JobDispatch:{$this->jobDispatch->id}", null, $this->jobDispatch->id);

        try {
            Job::$runningJob = $this->jobDispatch;

            $this->validateAuthContext();

            app()->call($callback);

            $this->jobDispatch->update([
                'status'       => JobDispatch::STATUS_COMPLETE,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if (config('queue.debug')) {
                static::logDebug("Exception caught for $this->jobDispatch: " . $exception->getMessage());
            }

            // Make sure we set the status to exception if there is a problem
            $this->jobDispatch->update([
                'status'       => JobDispatch::STATUS_EXCEPTION,
                'completed_at' => now(),
            ]);

            throw $exception;
        } finally {
            // Stop the heartbeat after job completes (success or failure)
            $heartbeat->stop();
        }
    }

    /**
     * Validate that user and team context are available before running the job.
     *
     * Jobs require authenticated context to ensure team-scoped operations work correctly.
     * Without this, queries silently return wrong results, models get null team_id,
     * and duplicate resolution fails — all silent bugs.
     *
     * Override requiresAuth() to return false for the rare job that legitimately
     * runs without user/team context.
     */
    protected function validateAuthContext(): void
    {
        if (!$this->requiresAuth()) {
            return;
        }

        if (!user()) {
            throw new \RuntimeException(
                'Job ' . static::class . ' requires an authenticated user but none is set. ' .
                'Ensure the job was dispatched from an authenticated context. ' .
                'CLI usage requires authentication before dispatching jobs. ' .
                'If this job legitimately runs without auth, override requiresAuth() to return false.',
            );
        }

        if (!team()) {
            throw new \RuntimeException(
                'Job ' . static::class . ' requires a team context but none is set. ' .
                'Ensure the dispatching user has a team assigned. ' .
                'If this job legitimately runs without a team, override requiresAuth() to return false.',
            );
        }
    }

    /**
     * Whether this job requires authenticated user and team context.
     *
     * Override to return false for jobs that legitimately run without auth
     * (e.g., system maintenance, cleanup tasks).
     */
    protected function requiresAuth(): bool
    {
        return true;
    }
}
