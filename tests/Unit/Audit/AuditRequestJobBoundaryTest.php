<?php

namespace Tests\Unit\Audit;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\Job as QueueJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\DanxServiceProvider;
use Newms87\Danx\Events\AuditRequestUpdatedEvent;
use Newms87\Danx\Logging\Audit\AuditLogLogger;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Audit\ErrorLogEntry;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A plain Laravel queued job — not a danx Job — that logs a line, and optionally an error, the
 * way App\Jobs\DevProgress\FetchDevProgressDayJob logs its GitHub failures.
 */
class LogsThroughTheQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $line, public ?string $error = null) {}

    public function handle(): void
    {
        Log::debug($this->line);

        if ($this->error) {
            Log::error($this->error, ['exception' => new RuntimeException($this->error)]);
        }
    }
}

/**
 * A plain queued job that logs a line and then fails.
 */
class FailsThroughTheQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $line, public string $failure) {}

    public function handle(): void
    {
        Log::debug($this->line);

        throw new RuntimeException($this->failure);
    }
}

/**
 * A queue job as a queue worker receives it (an SQS / Redis / database job — anything but a
 * SyncJob), carrying a real CallQueuedHandler payload so the worker unserializes and runs it.
 */
class WorkerDeliveredJob extends QueueJob implements JobContract
{
    public function __construct(Container $container, private readonly string $payload)
    {
        $this->container      = $container;
        $this->connectionName = 'sqs';
        $this->queue          = 'default';
    }

    public static function for(object $command): self
    {
        return new self(Container::getInstance(), json_encode([
            'uuid'          => (string)Str::uuid(),
            'displayName'   => $command::class,
            'job'           => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries'      => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff'       => null,
            'timeout'       => null,
            'data'          => [
                'commandName' => $command::class,
                'command'     => serialize($command),
            ],
        ]));
    }

    public function getJobId()
    {
        return json_decode($this->payload, true)['uuid'];
    }

    public function getRawBody()
    {
        return $this->payload;
    }

    public function attempts()
    {
        return 1;
    }
}

/**
 * A queue worker's process outlives the job it runs — a Horizon worker loops, and a warm Vapor
 * queue Lambda keeps its booted application (QueueHandler::$app) across invocations. The current
 * AuditRequest lives in a static (AuditDriver::$auditRequest), so it outlives the job too.
 *
 * SG-488, production 2026-09-04: a warm Lambda ran five TaskOrchestratorJobs (danx Jobs), each
 * complete by 02:58, then the 03:00 dev-progress:fetch FetchDevProgressDayJobs — plain Laravel
 * jobs, which never pass through danx Job::__unserialize() and so never replaced the static.
 * Their 108 GitHub "401 Bad credentials" ErrorLogEntries, and their debug lines, were written
 * onto the orchestrator jobs' AuditRequests, 2-5 minutes after those jobs completed.
 *
 * These tests run jobs through the real Illuminate\Queue\Worker::runJob() — the method the
 * Horizon daemon and Vapor's VaporWorker::runVaporJob() both call — with DanxServiceProvider
 * booted, so the listeners under test are the ones the provider registers.
 */
class AuditRequestJobBoundaryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DanxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('danx.audit.enabled', true);

        // The channel exactly as a consuming app declares it (gpt-manager's config/logging.php)
        $app['config']->set('logging.channels.auditlog', [
            'driver' => 'custom',
            'via'    => AuditLogLogger::class,
            'level'  => 'debug',
        ]);
        $app['config']->set('logging.default', 'auditlog');
    }

    protected function setUp(): void
    {
        parent::setUp();

        AuditDriver::$auditRequest = null;

        // The AuditRequest's realtime broadcast is not under test
        Event::fake([AuditRequestUpdatedEvent::class]);

        Schema::create('audit_request', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('children_count')->default(0);
            $table->string('session_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('environment')->nullable();
            $table->string('url', 512)->nullable();
            $table->json('request');
            $table->json('response')->nullable();
            $table->text('logs')->nullable();
            $table->text('profile')->nullable();
            $table->double('time');
            $table->unsignedInteger('api_log_count')->default(0);
            $table->unsignedInteger('error_log_count')->default(0);
            $table->unsignedInteger('log_line_count')->default(0);
            $table->timestamps(3);
        });

        Schema::create('job_dispatch', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ref');
            $table->string('status');
            $table->unsignedInteger('running_audit_request_id')->nullable();
            $table->unsignedInteger('dispatch_audit_request_id')->nullable();
            $table->timestamps();
        });

        Schema::create('error_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('root_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('hash')->unique();
            $table->string('error_class');
            $table->string('code');
            $table->string('level');
            $table->string('message', 512);
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('count');
            $table->dateTime('last_seen_at');
            $table->dateTime('last_notified_at')->nullable();
            $table->boolean('send_notifications')->default(true);
            $table->json('stack_trace')->nullable();
            $table->timestamps();
        });

        Schema::create('error_log_entry', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('error_log_id');
            $table->unsignedInteger('audit_request_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('message', 512)->default('');
            $table->longText('full_message')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_retryable')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        AuditDriver::$auditRequest = null;

        parent::tearDown();
    }

    public function test_a_job_run_later_in_the_same_worker_process_does_not_log_onto_the_previous_jobs_audit_request(): void
    {
        // Given - a job has run in this worker process and recorded its work on its own AuditRequest
        $this->runInWorker(new LogsThroughTheQueue('settling extraction'));
        $previousJobsAuditRequest = $this->auditRequestHolding('settling extraction');

        // When - a later job in the same process logs an error
        $this->runInWorker(new LogsThroughTheQueue('Processing dev progress for 2026-08-28', 'GitHub API Request Failed GET 401'));

        // Then - the error, and the later job's log lines, are on an AuditRequest of their own
        $errorEntry = ErrorLogEntry::sole();
        $this->assertNotEquals($previousJobsAuditRequest->id, $errorEntry->audit_request_id, 'the later job\'s error was recorded on the previous job\'s AuditRequest');
        $this->assertEquals(0, $previousJobsAuditRequest->errorLogEntries()->count());
        $this->assertStringNotContainsString('Processing dev progress', (string)$previousJobsAuditRequest->fresh()->logs);
        $this->assertEquals($this->auditRequestHolding('Processing dev progress for 2026-08-28')->id, $errorEntry->audit_request_id);
    }

    public function test_a_job_that_completes_releases_its_audit_request_when_it_ends(): void
    {
        // Given
        $this->runInWorker(new LogsThroughTheQueue('orchestrator pass complete'));
        $jobsAuditRequest = $this->auditRequestHolding('orchestrator pass complete');

        // When - anything at all is logged after the job has ended
        Log::error('logged after the job ended', ['exception' => new RuntimeException('logged after the job ended')]);

        // Then
        $this->assertEquals(0, $jobsAuditRequest->errorLogEntries()->count(), 'an error logged after the job ended was recorded on its AuditRequest');
        $this->assertStringNotContainsString('logged after the job ended', (string)$jobsAuditRequest->fresh()->logs);
    }

    public function test_a_failed_job_keeps_its_own_exception_and_the_next_job_does_not_inherit_its_audit_request(): void
    {
        // When - a job fails; the worker reports the exception AFTER the job's failure events
        $this->runInWorker(new FailsThroughTheQueue('about to fail', 'the job itself failed'));
        $failedJobsAuditRequest = $this->auditRequestHolding('about to fail');

        // Then - the job's own exception is on the job's own AuditRequest
        $ownError = ErrorLogEntry::where('message', 'like', '%the job itself failed%')->sole();
        $this->assertEquals($failedJobsAuditRequest->id, $ownError->audit_request_id, 'the failed job\'s own exception was not recorded on its AuditRequest');

        // When - the next job in the same process logs an error
        $this->runInWorker(new LogsThroughTheQueue('next job', 'next job failed a call'));

        // Then - the next job's error is not on the failed job's AuditRequest
        $nextError = ErrorLogEntry::where('message', 'like', '%next job failed a call%')->sole();
        $this->assertNotEquals($failedJobsAuditRequest->id, $nextError->audit_request_id, 'the next job\'s error was recorded on the failed job\'s AuditRequest');
        $this->assertEquals(1, $failedJobsAuditRequest->errorLogEntries()->count());
    }

    public function test_a_job_run_inline_with_dispatch_sync_stays_inside_its_callers_audit_request(): void
    {
        // Given - a caller (an HTTP request, a command, another job) with its own AuditRequest
        config()->set('queue.default', 'sync');
        $callersAuditRequest = AuditDriver::getAuditRequest();

        // When - it runs a plain job inline
        LogsThroughTheQueue::dispatchSync('inline step');

        // Then - a SyncJob's start and end are not unit-of-work boundaries: the caller keeps its AuditRequest
        $this->assertSame($callersAuditRequest, AuditDriver::$auditRequest);
        $this->assertStringContainsString('inline step', (string)$callersAuditRequest->fresh()->logs);
    }

    public function test_a_queue_worker_command_starting_does_not_write_onto_the_current_audit_request(): void
    {
        // Given - a warm Vapor queue Lambda whose last job failed: the job's AuditRequest is still current
        $this->runInWorker(new FailsThroughTheQueue('orchestrator job', 'orchestrator job failed'));
        $jobsAuditRequest = $this->auditRequestHolding('orchestrator job');
        $jobsRequest      = $jobsAuditRequest->fresh()->request;
        $auditRequestIds  = AuditRequest::pluck('id')->all();

        // When - the next invocation starts `vapor:work`
        event(new CommandStarting('vapor:work', new ArrayInput([]), new NullOutput));

        // Then - the job's recorded request is untouched, and no AuditRequest was made for the worker command
        $this->assertEquals($jobsRequest, $jobsAuditRequest->fresh()->request, 'vapor:work overwrote the previous job\'s recorded request');
        $this->assertEquals($auditRequestIds, AuditRequest::pluck('id')->all());
    }

    public function test_an_ordinary_command_still_records_itself_on_its_audit_request(): void
    {
        // When
        event(new CommandStarting('dev-progress:fetch', new ArrayInput([]), new NullOutput));

        // Then
        $this->assertEquals('dev-progress:fetch', AuditDriver::$auditRequest->fresh()->request['command']);
    }

    /**
     * Run a job exactly as a queue worker does: Illuminate\Queue\Worker::runJob(), which processes
     * the job and then reports any exception it threw.
     */
    private function runInWorker(object $command): void
    {
        $worker = app('queue.worker');

        (new ReflectionMethod(Worker::class, 'runJob'))->invoke($worker, WorkerDeliveredJob::for($command), 'sqs', new WorkerOptions);
    }

    private function auditRequestHolding(string $logLine): AuditRequest
    {
        return AuditRequest::where('logs', 'like', "%$logLine%")->sole();
    }
}
