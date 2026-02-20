<?php

namespace Newms87\Danx\Jobs;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Traits\HasDebugLogging;
use Newms87\Danx\Helpers\DateHelper;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Support\Heartbeat;
use ReflectionClass;
use Throwable;

abstract class Job implements ShouldQueue
{
    use Batchable, HasDebugLogging, InteractsWithQueue, Queueable, SerializesModels;

    protected ?JobDispatch $jobDispatch = null;

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
        $ref  = $this->ref();
        $name = class_basename(static::class);

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
                $jobDispatch->forceFill([
                    'user_id'         => user()?->id ?: null,
                    'name'            => $name,
                    'count'           => 1,
                    'will_timeout_at' => $this->getTimeoutAt(),
                ])->save();
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
            // This will be released when the job is just about to execute, we are debouncing all other redundant requests
            if (!LockHelper::get($this->jobDispatch->ref, 30)) {
                static::logDebug("Job {$this->jobDispatch->ref} is already running");
                $this->jobDispatch->update(['status' => JobDispatch::STATUS_ABORTED]);

                return $this;
            }

            $dispatcher = app(Dispatcher::class);

            // If this job is not supposed to be added to the Job Queue, then we want to dispatch it immediately
            if ($now || config('queue.default') === 'sync') {
                $dispatcher->dispatchSync($this);
            } else {
                $this->jobDispatch->update(['will_timeout_at' => $this->getTimeoutAt()]);
                app(Dispatcher::class)->dispatch($this->job ?: $this);
            }
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
        AuditDriver::$auditRequest = null;
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

        $ref     = $this->ref();
        $prefix  = '######';
        $jobName = "({$this->jobDispatch->id}) --- $ref";
        $traceStatus = Cache::get('debug:trace_enabled') ? 'TRACE' : 'DEBUG';
        static::logDebug("$prefix Handling  $jobName (log level: $traceStatus)");

        $jobBatch = $this->jobDispatch->jobBatch;

        try {
            $this->executeJob();
            if ($jobBatch) {
                LockHelper::acquire($jobBatch);
                $jobBatch->refresh();
                $jobBatch->pending_jobs -= 1;
                $jobBatch->save();
                LockHelper::release($jobBatch);

                if ($jobBatch->pending_jobs === 0) {
                    $jobBatch->finished_at = now()->timestamp;
                    if ($jobBatch->on_complete) {
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
                $jobBatch->save();
            }

            $time = DateHelper::timerStr(static::class);
            static::logDebug("$prefix Completed $jobName --- ($time)");
        } catch (Throwable $exception) {
            $time = DateHelper::timerStr(static::class);
            static::logDebug("$prefix Failed   $jobName --- ($time)");
            if ($jobBatch) {
                $jobBatch->failed_jobs += 1;
                $jobBatch->save();
            }
            throw $exception;
        } finally {
            // Signal that this audit request is complete (Jobs run in app instances that do not terminate after every job)
            AuditDriver::terminate();

            // Reset the running job reference so subsequent code doesn't think we're still in a job
            self::$runningJob = null;
        }
    }

    /**
     * Handle a job failure (called by Laravel when job fails/times out externally)
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

        // Release the lock when we are about to execute the job, so other jobs can stack up
        // Anything attempting to run the same job is redundant before this point
        LockHelper::release($this->jobDispatch->ref);

        // Run the Job and timestamp the run time
        $this->jobDispatch->update([
            'status' => JobDispatch::STATUS_RUNNING,
            'ran_at' => now(),
        ]);

        // Start heartbeat monitoring for the job execution
        $heartbeat = Heartbeat::start("Job:{$this->jobDispatch->name}|JobDispatch:{$this->jobDispatch->id}", null, $this->jobDispatch->id);

        try {
            Job::$runningJob = $this->jobDispatch;

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
}
