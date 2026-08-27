<?php

namespace Tests\Unit\Api;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Redis;
use Newms87\Danx\Api\Api;
use Newms87\Danx\Models\Audit\ApiLog;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

require_once __DIR__ . '/support/helpers.php';

/**
 * Deterministic, controllable stand-in for a real async transport (a real
 * GuzzleHttp\Handler\CurlMultiHandler in production). Every call it receives gets its
 * own pending GuzzleHttp\Promise\Promise; a call only settles once tick() has been
 * invoked at least $afterTicks times for that specific call — this lets a test force
 * "attempt 1 is still pending when attempt 2 fires" deterministically, without any
 * real I/O, real sleeping, or real timing dependency (call site chooses a real but
 * effectively-zero-or-huge soft timeout in seconds; settlement timing is entirely
 * driven by explicit tick counts instead).
 *
 * A call index with NO script entry never settles on its own — it stays pending for
 * the lifetime of the test (used to prove a hedge attempt fires while a sibling is
 * still genuinely in flight).
 */
class ScriptedHedgeHandler
{
    /** @var array<int, array{request: RequestInterface, promise: Promise, ticks: int, settled: bool}> */
    public array $calls = [];

    private array $script;

    /**
     * @param array $script Keyed by 1-based call index. Each entry:
     *                      ['afterTicks' => int, 'response' => Response] or
     *                      ['afterTicks' => int, 'exception' => \Throwable].
     *                      A missing index never settles.
     */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $index = count($this->calls) + 1;

        $this->calls[$index] = [
            'request' => $request,
            'promise' => new Promise(),
            'ticks'   => 0,
            'settled' => false,
        ];

        return $this->calls[$index]['promise'];
    }

    public function tick(): void
    {
        foreach ($this->calls as $index => &$call) {
            if ($call['settled']) {
                continue;
            }

            $config = $this->script[$index] ?? null;

            if (!$config) {
                continue;
            }

            $call['ticks']++;

            if ($call['ticks'] >= ($config['afterTicks'] ?? 1)) {
                $call['settled'] = true;

                if (isset($config['exception'])) {
                    $call['promise']->reject($config['exception']);
                } else {
                    $call['promise']->resolve($config['response']);
                }
            }
        }
        unset($call);

        PromiseUtils::queue()->run();
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}

/**
 * Real (non-test-double) Api subclass used for every hedging scenario. A short
 * default requestTimeout keeps any accidentally-real network attempt fast to fail
 * rather than hanging a test.
 *
 * Overrides hedgeClockNow() with a deterministic counter (advances by exactly 1.0
 * "second" on every call) INSTEAD of real microtime(true). callWithHedging() calls
 * hedgeClockNow() exactly once per poll loop iteration in every scenario these tests
 * exercise (no within-attempt retries), and ScriptedHedgeHandler::tick() is also
 * driven exactly once per iteration — so a soft timeout of N and a scripted
 * afterTicks of M are directly comparable integers ("N loop iterations" vs "M
 * ticks"), with zero dependency on real wall-clock timing or usleep() scheduling
 * jitter. This is what makes "attempt 2 fires while attempt 1 is still pending, then
 * attempt 1 wins before attempt 3's threshold" deterministic instead of flaky.
 */
class HedgeTestApi extends Api
{
    public static string $serviceName = 'hedge-test';

    protected string $baseApiUrl = 'https://hedge-test.invalid/api';

    protected int $requestTimeout = 5;

    private float $fakeClock = 0.0;

    protected function hedgeClockNow(): float
    {
        $this->fakeClock += 1.0;

        return $this->fakeClock;
    }
}

/**
 * Minimal always-retryable checker — danx's own api_retryable_checker config default
 * is null (nothing retryable at the API level unless the CONSUMING app configures
 * one, e.g. gpt-manager's App\Services\Error\RetryableApiExceptionService, which
 * isn't reachable from this standalone package test). Used only by the ordinary-
 * retry regression test, which needs the pre-existing retry loop to actually retry.
 */
class AlwaysRetryableChecker
{
    public static function isRetryable(\Throwable $exception): bool
    {
        return true;
    }
}

/**
 * Same as HedgeTestApi but with the fork+SIGKILL backstop opted in by default
 * (mirrors OpenAiApi in the consuming app) — used specifically to prove hedge
 * attempts force it back off regardless of the class default.
 */
class HedgeBackstopEnabledTestApi extends HedgeTestApi
{
    protected bool $timeoutBackstopEnabled = true;
}

/**
 * Overrides createHandler() (a protected, intentionally-overridable seam already used
 * by client()) to route the REAL executeCall() path through a MockHandler while still
 * attaching the exact same handleRequest()/handleResponse() logging middleware
 * production uses. Deliberately NOT using setOverrideClient() here — that mechanism
 * replaces the entire client (middleware included, see Api::client()'s own body),
 * which would make ApiLog rows never get written at all; this test needs real ApiLog
 * rows to assert the regression guard (ordinary retries keep attempt_number=1/
 * parent_api_log_id=null).
 */
class HedgeRegressionRetryTestApi extends Api
{
    public static string $serviceName = 'hedge-regression-retry-test';

    protected string $baseApiUrl = 'https://hedge-regression-retry.invalid/api';

    public ?MockHandler $mock = null;

    protected function createHandler(): HandlerStack
    {
        $callable = fn(callable $handler) => fn(RequestInterface $request, array $options = []) => $this->handleRequest($handler, $request, $options);

        $stack = HandlerStack::create($this->mock);
        $stack->push($callable);

        return $stack;
    }
}

/**
 * Verifies the hedged-request feature end-to-end against the REAL Api::call() /
 * callWithHedging() implementation — no re-implementation of the hedging rules is
 * used anywhere in this file; every scenario drives the actual shipped code via a
 * deterministic, tick-controlled fake transport (ScriptedHedgeHandler) instead of
 * real I/O or real sleeping, so timing-dependent behavior (soft timeout thresholds,
 * "still in flight" races) is exercised precisely and fast.
 *
 * Uses an in-memory SQLite database (via Orchestra\Testbench) with danx's real
 * migrations applied, so every assertion reads real ApiLog rows written by the real
 * ApiLog::logRequest()/logResponse()/logResponseError() methods — never hand-built
 * fixtures standing in for what the shipped code would have written.
 */
class ApiHedgingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        // In-memory sqlite: each test method gets a genuinely fresh, isolated
        // database automatically (a new connection to ':memory:' is a brand new
        // empty database) — no shared state with any real Postgres database, and no
        // teardown/cleanup needed between tests.
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Runs each fixture migration's up() directly (verbatim copies of the real,
        // unmodified api_logs-touching migrations — 0000/0005/0011/0013/0017; 0014
        // is deliberately excluded — it runs a Postgres-only information_schema
        // introspection query with no sqlite equivalent, and this suite only needs
        // the api_logs table) rather than via loadMigrationsFrom()/artisan migrate:
        // Testbench's InteractsWithMigrations brackets a tracked migrate with an
        // automatic migrate:rollback in tearDown, and 0011's down() (status_code
        // NOT NULL again) fails against sqlite's rebuild-table strategy once a row
        // with a NULL status_code exists — a test-infra rollback-ordering artifact,
        // not a real bug. Every test method gets a genuinely fresh, empty ':memory:'
        // database anyway (a new connection to ':memory:' is a new database), so no
        // explicit teardown/rollback is needed here at all.
        foreach (glob(__DIR__ . '/support/migrations/*.php') as $migrationFile) {
            (require $migrationFile)->up();
        }

        config(['danx.audit.api.enabled' => true]);
        config(['danx.errors.api_retry_count' => 0]);
        config(['danx.errors.api_retry_delay_ms' => 1]);
    }

    private function successResponse(string $body = '{"ok":true}'): Response
    {
        return new Response(200, [], $body);
    }

    private function retryableFailure(): RequestException
    {
        $request = new Psr7Request('POST', 'https://hedge-test.invalid/api/endpoint');

        return RequestException::create($request, new Response(500, [], '{"error":"boom"}'));
    }

    // ------------------------------------------------------------------
    // Regression guard — the un-hedged path must be provably untouched.
    // ------------------------------------------------------------------

    public function test_no_soft_timeout_uses_the_unaffected_executeCall_path(): void
    {
        Redis::shouldReceive('ttl')->once()->andReturn(-2);

        $api      = new HedgeRegressionRetryTestApi;
        $api->mock = new MockHandler([$this->successResponse('{"hello":"world"}')]);

        $api->get('endpoint');

        $this->assertSame('{"hello":"world"}', (string)$api->getResponse()->getBody());

        $log = ApiLog::sole();
        $this->assertSame(1, $log->attempt_number);
        $this->assertNull($log->parent_api_log_id);
        $this->assertNull($log->is_hedge_winner);
    }

    public function test_ordinary_retry_loop_never_touches_hedge_chain_fields(): void
    {
        config(['danx.errors.api_retry_count' => 3]);
        config(['danx.errors.api_retryable_checker' => AlwaysRetryableChecker::class]);
        Redis::shouldReceive('ttl')->once()->andReturn(-2);

        $api       = new HedgeRegressionRetryTestApi;
        $api->mock = new MockHandler([
            new Response(500, [], '{"error":"1"}'),
            new Response(500, [], '{"error":"2"}'),
            $this->successResponse(),
        ]);

        $api->get('endpoint');

        $logs = ApiLog::orderBy('id')->get();
        $this->assertCount(3, $logs, 'The ordinary retry loop must still create one ApiLog row per real HTTP try');

        foreach ($logs as $log) {
            $this->assertSame(1, $log->attempt_number, 'An ordinary (non-hedged) retry must never carry a hedge attempt_number');
            $this->assertNull($log->parent_api_log_id, 'An ordinary (non-hedged) retry must never carry a hedge parent_api_log_id');
            $this->assertNull($log->is_hedge_winner);
        }
    }

    // ------------------------------------------------------------------
    // Hedging scenarios
    // ------------------------------------------------------------------

    public function test_fast_response_never_constructs_a_second_attempt(): void
    {
        Redis::shouldReceive('ttl')->once()->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            1 => ['afterTicks' => 1, 'response' => $this->successResponse()],
        ]);

        $api = new HedgeTestApi;
        $api->setOverrideBaseHandler($handler);
        $api->setNextSoftTimeout(60); // never real-elapses in this test

        $api->get('endpoint');

        $this->assertSame(1, $handler->callCount(), 'Attempt 2 must never be constructed/fired when attempt 1 settles first');
        $this->assertSame(200, $api->getResponse()->getStatusCode());

        $log = ApiLog::sole();
        $this->assertSame(1, $log->attempt_number);
        $this->assertTrue((bool)$log->is_hedge_winner);
    }

    public function test_attempt_1_wins_after_a_hedge_was_fired(): void
    {
        Redis::shouldReceive('ttl')->twice()->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            // With hedgeClockNow() advancing 1.0/call, softTimeout=5 fires attempt 2
            // at loop iteration 5 — attempt 1 (threshold 7) is still pending at that
            // point, then resolves at iteration 7, well before attempt 3's iteration-
            // 10 threshold.
            1 => ['afterTicks' => 7, 'response' => $this->successResponse('{"winner":"attempt1"}')],
            // Still pending at iteration 7 (when attempt 1 wins: 2 ticks so far), then
            // reaches its threshold on the loser grace window's first extra tick.
            2 => ['afterTicks' => 3, 'response' => $this->successResponse('{"winner":"attempt2"}')],
        ]);

        $api = new HedgeTestApi;
        $api->setOverrideBaseHandler($handler);
        $api->setNextSoftTimeout(5);

        $api->get('endpoint');

        $this->assertSame(2, $handler->callCount(), 'Attempt 2 must have fired since attempt 1 was still pending past the soft timeout');
        $this->assertSame('{"winner":"attempt1"}', (string)$api->getResponse()->getBody());

        $logs = ApiLog::orderBy('id')->get();
        $this->assertCount(2, $logs);

        $winner = $logs->firstWhere('attempt_number', 1);
        $loser  = $logs->firstWhere('attempt_number', 2);

        $this->assertNull($winner->parent_api_log_id);
        $this->assertTrue((bool)$winner->is_hedge_winner);

        $this->assertSame($winner->id, $loser->parent_api_log_id);
        $this->assertFalse((bool)$loser->is_hedge_winner, 'Attempt 2 completed (during the grace window) but was not first — must be a confirmed false, not left ambiguous');
    }

    public function test_attempt_2_wins(): void
    {
        Redis::shouldReceive('ttl')->twice()->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            // Still pending at iteration 7 (when attempt 2 wins), then reaches its
            // threshold on the loser grace window's first extra tick (attempt 1 gets
            // ticked twice more during the bounded grace period after losing).
            1 => ['afterTicks' => 8, 'response' => $this->successResponse('{"winner":"attempt1"}')],
            // Attempt 2 fires at iteration 5, first ticked at iteration 6, resolves
            // on its 2nd tick (iteration 7) — well before attempt 3's iteration-10
            // threshold.
            2 => ['afterTicks' => 2, 'response' => $this->successResponse('{"winner":"attempt2"}')],
        ]);

        $api = new HedgeTestApi;
        $api->setOverrideBaseHandler($handler);
        $api->setNextSoftTimeout(5);

        $api->get('endpoint');

        $this->assertSame(2, $handler->callCount());
        $this->assertSame('{"winner":"attempt2"}', (string)$api->getResponse()->getBody());

        $logs   = ApiLog::orderBy('id')->get();
        $winner = $logs->firstWhere('attempt_number', 2);
        $loser  = $logs->firstWhere('attempt_number', 1);

        $this->assertTrue((bool)$winner->is_hedge_winner);
        $this->assertSame($loser->id, $winner->parent_api_log_id);
        $this->assertFalse((bool)$loser->is_hedge_winner);
    }

    public function test_third_attempt_fires_and_wins_with_correct_chain_columns(): void
    {
        Redis::shouldReceive('ttl')->times(3)->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            // 1 and 2 intentionally carry NO script entry: they never settle at all,
            // proving they stay genuinely ambiguous (is_hedge_winner left null) even
            // after the bounded loser grace window closes.
            3 => ['afterTicks' => 1, 'response' => $this->successResponse('{"winner":"attempt3"}')],
        ]);

        $api = new HedgeTestApi;
        $api->setOverrideBaseHandler($handler);
        $api->setNextSoftTimeout(5);

        $api->get('endpoint');

        $this->assertSame(3, $handler->callCount(), 'All 3 hedge attempts (the HEDGE_MAX_ATTEMPTS cap) must have fired');
        $this->assertSame('{"winner":"attempt3"}', (string)$api->getResponse()->getBody());

        $logs = ApiLog::orderBy('id')->get();
        $this->assertCount(3, $logs);

        [$log1, $log2, $log3] = [
            $logs->firstWhere('attempt_number', 1),
            $logs->firstWhere('attempt_number', 2),
            $logs->firstWhere('attempt_number', 3),
        ];

        $this->assertNull($log1->parent_api_log_id);
        $this->assertSame($log1->id, $log2->parent_api_log_id);
        $this->assertSame($log2->id, $log3->parent_api_log_id);

        $this->assertTrue((bool)$log3->is_hedge_winner);
        // Genuinely never observed to reach a terminal state — must stay ambiguous
        // (null), never coerced to false just because they lost the race.
        $this->assertNull($log1->fresh()->is_hedge_winner);
        $this->assertNull($log2->fresh()->is_hedge_winner);
    }

    public function test_all_hedged_attempts_failing_throws_the_last_failure(): void
    {
        Redis::shouldReceive('ttl')->times(3)->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            1 => ['afterTicks' => 1, 'exception' => $this->retryableFailure()],
            2 => ['afterTicks' => 1, 'exception' => $this->retryableFailure()],
            3 => ['afterTicks' => 1, 'exception' => $this->retryableFailure()],
        ]);

        $api = new HedgeTestApi;
        $api->setOverrideBaseHandler($handler);
        $api->setNextSoftTimeout(5);

        try {
            $api->get('endpoint');
            $this->fail('Expected an exception when every hedged attempt fails');
        } catch (Exception $exception) {
            $this->assertStringContainsString('boom', $exception->getMessage());
        }

        $this->assertSame(3, $handler->callCount());

        $logs = ApiLog::orderBy('id')->get();
        $this->assertCount(3, $logs);

        foreach ($logs as $log) {
            $this->assertNull($log->is_hedge_winner, 'A permanently-failed attempt is a confirmed non-winner but the spec reserves is_hedge_winner=false for a real completed response — null is correct here too since these never produced a response');
        }
    }

    public function test_throttle_is_invoked_once_per_real_hedge_attempt(): void
    {
        // Exactly 2 Redis::ttl() calls == exactly 2 throttle() calls == exactly 2
        // hedge attempts fired. Mockery fails the test if the real count differs.
        Redis::shouldReceive('ttl')->twice()->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            2 => ['afterTicks' => 1, 'response' => $this->successResponse()],
        ]);

        $api = new HedgeTestApi;
        $api->setOverrideBaseHandler($handler);
        $api->setNextSoftTimeout(5);

        $api->get('endpoint');

        $this->assertSame(2, $handler->callCount());
    }

    public function test_hedge_attempts_disable_the_timeout_backstop_regardless_of_class_default(): void
    {
        Redis::shouldReceive('ttl')->once()->andReturn(-2);

        $handler = new ScriptedHedgeHandler([
            1 => ['afterTicks' => 1, 'response' => $this->successResponse()],
        ]);

        $api = new HedgeBackstopEnabledTestApi;
        $api->setOverrideBaseHandler($handler);

        $this->assertTrue($this->readProtected($api, 'timeoutBackstopEnabled'), 'Sanity check: this test API must default to the backstop being ENABLED');

        $buildPlan = new ReflectionMethod(Api::class, 'buildRequestPlan');
        $buildPlan->setAccessible(true);
        $plan = $buildPlan->invoke($api, 'GET', 'endpoint', '', []);

        $fireAttempt = new ReflectionMethod(Api::class, 'fireHedgeAttempt');
        $fireAttempt->setAccessible(true);
        $attempt = $fireAttempt->invoke($api, $plan, 1, null, microtime(true));

        $this->assertFalse($this->readProtected($attempt['api'], 'timeoutBackstopEnabled'), 'A hedge attempt clone must always have the backstop forced off, even when the class default is true');
    }

    private function readProtected(object $object, string $property)
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
