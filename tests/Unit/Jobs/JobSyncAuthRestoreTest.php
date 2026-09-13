<?php

namespace Tests\Unit\Jobs;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Newms87\Danx\DanxServiceProvider;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Traits\HasTeams;
use Orchestra\Testbench\TestCase;

/**
 * A minimal Authenticatable stand-in for a consuming app's own User model — danx does not ship
 * one; every app configures its own via `auth.providers.users.model`.
 */
class SyncAuthRestoreTestUser extends Model implements Authenticatable
{
    use AuthenticatableTrait, HasTeams;

    protected $table = 'users';

    protected $guarded = [];
}

/**
 * A minimal stand-in for a consuming app's own Team model, configured via `danx.models.team` —
 * exactly how gpt-manager points that config at its own `App\Models\Team\Team` (a plain
 * auto-increment-id model, not danx's own UUID-keyed `Newms87\Danx\Models\Team\Team`).
 */
class SyncAuthRestoreTestTeam extends Model
{
    protected $table = 'teams';

    protected $guarded = [];
}

/**
 * A danx Job that records which user/team it observed DURING its own run() — separate from what
 * the test asserts the CALLER is left with afterward — so a test can prove the job still ran
 * under its own identity, not merely that nothing broke.
 */
class SyncAuthRestoreTestJob extends Job
{
    public static ?int $observedUserId = null;

    public static string|int|null $observedTeamId = null;

    public function __construct(private readonly string $refId)
    {
        parent::__construct();
    }

    public function ref(): string
    {
        return $this->refId;
    }

    public function run(): void
    {
        static::$observedUserId = Auth::guard()->user()?->id;
        static::$observedTeamId = Auth::guard()->user()?->currentTeam?->id;
    }
}

/**
 * SG-494: a job run SYNCHRONOUSLY (danx's own dispatchSync() path, or the `sync` queue
 * connection) logs its own user/team into the shared Auth guard via Job::__unserialize() and
 * never restored whoever was authenticated before it ran. Since a sync job executes INLINE in
 * the calling request/command/test process, that left the caller's own identity silently
 * replaced by the job's for the rest of that same process.
 *
 * These tests run Job::dispatch() for real (real serialize -> SyncQueue -> unserialize ->
 * handle()), never a re-implementation of the restore rule, per this repo's own
 * "verify by calling the shipped code" standard.
 */
class JobSyncAuthRestoreTest extends TestCase
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

        $app['config']->set('auth.providers.users.model', SyncAuthRestoreTestUser::class);
        $app['config']->set('danx.models.team', SyncAuthRestoreTestTeam::class);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('job_dispatch', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ref');
            $table->string('status');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('team_id')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->string('name')->nullable();
            $table->unsignedBigInteger('job_batch_id')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('will_timeout_at')->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('run_time_ms')->nullable();
            $table->unsignedInteger('running_audit_request_id')->nullable();
            $table->unsignedInteger('dispatch_audit_request_id')->nullable();
            $table->timestamps();
        });

        SyncAuthRestoreTestJob::$observedUserId = null;
        SyncAuthRestoreTestJob::$observedTeamId = null;
    }

    public function test_a_job_run_synchronously_restores_the_callers_auth_when_it_finishes(): void
    {
        Config::set('queue.default', 'sync');

        $callerUser              = SyncAuthRestoreTestUser::create(['name' => 'Caller', 'email' => 'caller@example.com']);
        $callerTeam              = SyncAuthRestoreTestTeam::create(['name' => 'Caller Team']);
        $callerUser->currentTeam = $callerTeam;
        Auth::guard()->setUser($callerUser);

        $jobUser = SyncAuthRestoreTestUser::create(['name' => 'Job Owner', 'email' => 'job-owner@example.com']);
        $jobTeam = SyncAuthRestoreTestTeam::create(['name' => 'Job Team']);

        $job = new SyncAuthRestoreTestJob('sync-auth-restore-' . uniqid('', true));
        $job->getJobDispatch()->forceFill([
            'user_id' => $jobUser->id,
            'team_id' => $jobTeam->id,
        ])->save();

        $job->dispatch();

        $this->assertSame($jobUser->id, SyncAuthRestoreTestJob::$observedUserId, 'the job must run as its OWN user while it executes');
        $this->assertSame($jobTeam->id, SyncAuthRestoreTestJob::$observedTeamId, 'the job must run under its OWN team while it executes');

        $this->assertNotNull(Auth::guard()->user(), 'the caller was authenticated before the sync job ran and must still be authenticated afterward');
        $this->assertSame($callerUser->id, Auth::guard()->user()->id, 'a synchronously-run job left its own user logged in instead of restoring the caller');
        $this->assertSame($callerTeam->id, Auth::guard()->user()->currentTeam?->id, "a synchronously-run job left its own team active instead of restoring the caller's team");
    }

    public function test_a_job_run_synchronously_with_no_caller_authenticated_leaves_no_one_authenticated_afterward(): void
    {
        Config::set('queue.default', 'sync');

        Auth::guard()->forgetUser();

        $jobUser = SyncAuthRestoreTestUser::create(['name' => 'Job Owner', 'email' => 'job-owner-2@example.com']);
        $jobTeam = SyncAuthRestoreTestTeam::create(['name' => 'Job Team 2']);

        $job = new SyncAuthRestoreTestJob('sync-auth-restore-' . uniqid('', true));
        $job->getJobDispatch()->forceFill([
            'user_id' => $jobUser->id,
            'team_id' => $jobTeam->id,
        ])->save();

        $job->dispatch();

        $this->assertSame($jobUser->id, SyncAuthRestoreTestJob::$observedUserId, 'the job must still run as its OWN user while it executes');
        $this->assertNull(Auth::guard()->user(), 'an unauthenticated caller must stay unauthenticated after a synchronous job runs');
    }

    public function test_a_real_queue_worker_still_keeps_the_jobs_own_user_set_after_it_runs(): void
    {
        // Given - a "worker process" that happens to have some leftover auth state (e.g. from a
        // previous job in the same long-running worker loop) before this job is delivered.
        $leftoverUser = SyncAuthRestoreTestUser::create(['name' => 'Previous Job', 'email' => 'previous-job@example.com']);
        Auth::guard()->setUser($leftoverUser);

        $jobUser = SyncAuthRestoreTestUser::create(['name' => 'Job Owner', 'email' => 'job-owner-3@example.com']);
        $jobTeam = SyncAuthRestoreTestTeam::create(['name' => 'Job Team 3']);

        $job = new SyncAuthRestoreTestJob('async-worker-test-' . uniqid('', true));
        $job->getJobDispatch()->forceFill([
            'user_id' => $jobUser->id,
            'team_id' => $jobTeam->id,
        ])->save();

        // When - the job is delivered exactly as a real queue worker delivers one (a non-Sync
        // Illuminate\Contracts\Queue\Job), never through danx's own dispatchSync() path.
        $payload = [
            'uuid'          => (string)\Illuminate\Support\Str::uuid(),
            'displayName'   => get_class($job),
            'job'           => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries'      => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff'       => null,
            'timeout'       => null,
            'data'          => [
                'commandName' => get_class($job),
                'command'     => serialize($job),
            ],
        ];

        $queueJob = new WorkerDeliveredJobDouble(app(), json_encode($payload));
        app('queue.worker');
        (new \ReflectionMethod(\Illuminate\Queue\Worker::class, 'runJob'))->invoke(
            app('queue.worker'), $queueJob, 'sqs', new \Illuminate\Queue\WorkerOptions
        );

        // Then - the job ran as its own user, and (unlike the SyncJob case) the worker keeps the
        // job's own identity set — there is no "caller" in a real worker process to hand back to.
        $this->assertSame($jobUser->id, SyncAuthRestoreTestJob::$observedUserId);
        $this->assertNotNull(Auth::guard()->user());
        $this->assertSame($jobUser->id, Auth::guard()->user()->id, 'a real queue worker must keep running each job as its own user after it finishes');
    }
}

/**
 * A queue job exactly as a real queue worker receives it (SQS/Redis/database — anything but a
 * SyncJob), carrying a real CallQueuedHandler payload so the worker unserializes and runs it for
 * real. Mirrors AuditRequestJobBoundaryTest's WorkerDeliveredJob fixture.
 */
class WorkerDeliveredJobDouble extends \Illuminate\Queue\Jobs\Job implements \Illuminate\Contracts\Queue\Job
{
    public function __construct(\Illuminate\Container\Container $container, private readonly string $payload)
    {
        $this->container      = $container;
        $this->connectionName = 'sqs';
        $this->queue          = 'default';
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
