<?php

namespace Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Newms87\Danx\Api\Api;
use Newms87\Danx\Exceptions\ApiTimeoutBackstopException;
use Newms87\Danx\Support\ProcessFork;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Test-only Api subclass exposing the protected requestWithTimeoutBackstop() method
 * directly, so tests can drive it with a Guzzle 'timeout' value DECOUPLED from the
 * fork-based backstop's own timeoutSeconds argument — this is what lets
 * test_backstop_interrupts_a_hang_curls_own_timeout_does_not_catch() simulate curl's
 * own client-side timeout mechanism genuinely failing to fire (Guzzle timeout=0, i.e.
 * "no timeout") while still proving the independent fork+SIGKILL backstop works.
 */
class BackstopTestApi extends Api
{
    public static string $serviceName = 'backstop-test';

    protected string $baseApiUrl = 'http://host.docker.internal:8899';

    // Opt-in required — defaults to false on the base Api class (see
    // Api::$timeoutBackstopEnabled's docblock).
    protected bool $timeoutBackstopEnabled = true;

    public function callWithBackstop(string $type, string $endpoint, array $requestOptions, int $timeoutSeconds)
    {
        $client = $this->client();

        // requestWithTimeoutBackstop() receives an already-fully-built absolute URL in
        // real usage (Api::call() builds it before invoking this method) — the raw
        // Guzzle client here has no base_uri configured, so build it the same way here.
        $url = rtrim($this->baseApiUrl, '/') . '/' . ltrim($endpoint, '/');

        return $this->requestWithTimeoutBackstop($client, $type, $url, $requestOptions, $timeoutSeconds);
    }
}

/**
 * Test-only Api subclass that always reports a backstop timeout, WITHOUT any real
 * fork/network involved — used to verify call()'s retry-loop CONTROL FLOW around
 * ApiTimeoutBackstopException (invocation count, no API-level retry) fast and
 * deterministically, isolated from the real fork/slow-server mechanics already
 * covered by the tests above.
 */
class AlwaysBackstopTimesOutApi extends Api
{
    public static string $serviceName = 'always-backstop-test';

    protected string $baseApiUrl = 'https://always-backstop.test';

    public int $invocationCount = 0;

    protected function requestWithTimeoutBackstop(Client $client, string $type, string $url, array $requestOptions, int $timeoutSeconds): ResponseInterface
    {
        $this->invocationCount++;

        throw new ApiTimeoutBackstopException($this->getServiceName(), $type, $url, $timeoutSeconds);
    }
}

/**
 * Real, end-to-end verification of Api::requestWithTimeoutBackstop() — the
 * fork()+SIGKILL-based hard backstop against a hung outbound HTTP request that
 * curl's own client-side timeout has, in real production incidents, occasionally
 * failed to enforce (see ApiTimeoutBackstopException's class docblock, and
 * requestWithTimeoutBackstop()'s own docblock, for the full incident context and
 * the empirical reproductions that ruled out an in-process pcntl_alarm() design).
 *
 * These tests use a REAL TCP server (slow-server.py in the consuming application's
 * .junk/ directory, started out-of-band before this suite runs — a plain socket
 * server that accepts a connection, reads the request, and sends ZERO response bytes
 * forever) rather than a MockHandler: MockHandler never touches a real socket, so it
 * cannot prove anything about whether a real blocking call gets interrupted — only a
 * real hung connection can prove that.
 *
 * REQUIRES: a slow-server process actually listening on host.docker.internal:8899
 * before this suite runs. If pcntl/posix are unavailable, every test here is skipped
 * (the backstop's own code falls back to a no-op passthrough in that case — see
 * requestWithTimeoutBackstop()'s function_exists() guard).
 */
class ApiTimeoutBackstopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix extensions not available — backstop is a no-op passthrough on this platform');
        }
    }

    /**
     * THE core verification the operator asked for: prove the fork+SIGKILL backstop
     * interrupts a genuinely hung call that curl's OWN timeout mechanism does NOT
     * catch — not "a timeout fires eventually" (curl's own timeout already does that
     * in the common case), but specifically "the backstop still saves us when curl's
     * own mechanism has failed."
     *
     * Simulates curl's own timeout failing to fire by setting Guzzle's 'timeout' to 0
     * ("no timeout" — curl will genuinely wait forever) for THIS ONE TEST — while the
     * backstop is independently armed via a SEPARATE argument (2s requested +
     * Api::TIMEOUT_BACKSTOP_BUFFER_SECONDS(5) buffer = 7s actual armed window). If
     * the backstop did not work independently of curl's own timer, this request
     * would hang until the test runner's own PHPUnit/process timeout (minutes), not
     * ~7 seconds.
     */
    public function test_backstop_interrupts_a_hang_curls_own_timeout_does_not_catch(): void
    {
        $api = new BackstopTestApi;

        $start = microtime(true);

        try {
            $api->callWithBackstop('POST', 'never-responds', [
                'timeout' => 0, // 0 = unlimited in Guzzle/curl — curl's own timeout will NOT fire
                'body'    => '{}',
                'headers' => [],
                'query'   => [],
            ], 2);

            $this->fail('Expected ApiTimeoutBackstopException was not thrown — the request hung past the backstop window with zero interruption.');
        } catch (ApiTimeoutBackstopException $exception) {
            $elapsed = microtime(true) - $start;

            // Fired at (approximately) the backstop's own armed window (requested 2s +
            // the 5s buffer = 7s), NOT curl's disabled timeout (which would never fire)
            // and not PHPUnit's own eventual process timeout (minutes away).
            $this->assertGreaterThanOrEqual(6.0, $elapsed, 'Backstop fired suspiciously early');
            $this->assertLessThan(15.0, $elapsed, 'Backstop did not fire within a reasonable window of its 7s armed duration — curl\'s disabled timeout was not actually bypassed');
            $this->assertStringContainsString('backstop-test', $exception->getMessage());
            $this->assertStringContainsString('7s', $exception->getMessage());
        }
    }

    /**
     * Confirms a call that finishes WELL within the backstop window returns the real
     * response untouched (status, headers, body) — proving the fork/flatten/
     * reconstruct round-trip preserves the actual HTTP response correctly, not just
     * that the timeout path works. Uses the same real slow-server host but a fast
     * endpoint the server responds to immediately... — slow-server.py never responds
     * on any path, so this test instead proves the round-trip via a MockHandler swap
     * (setOverrideClient) — the fork mechanics run identically for a MockHandler
     * client, only the outbound transport differs.
     */
    public function test_backstop_preserves_a_real_response_on_success(): void
    {
        $api = new BackstopTestApi;

        $mockHandler = new MockHandler([
            new Response(201, ['X-Test-Header' => 'abc'], '{"hello":"world"}'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $api->setOverrideClient($client);

        $response = $api->callWithBackstop('POST', 'fast-endpoint', [
            'timeout' => 5,
            'body'    => '{}',
            'headers' => [],
            'query'   => [],
        ], 5);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('abc', $response->getHeaderLine('X-Test-Header'));
        $this->assertSame('{"hello":"world"}', (string)$response->getBody());
    }

    /**
     * Confirms a real (non-hang) Guzzle failure — curl's own timeout genuinely firing
     * normally, well within the backstop window — reconstructs correctly across the
     * fork boundary as a RequestException, preserving the original message text (the
     * word "timeout" specifically, since Api::call()'s isTimeoutException() regexes
     * the message to classify it) so the caller's EXISTING classification logic
     * behaves exactly as it would without the backstop in the picture.
     */
    public function test_backstop_reconstructs_a_normal_curl_timeout_exception(): void
    {
        $api = new BackstopTestApi;

        $mockHandler = new MockHandler([
            new RequestException('cURL error 28: Operation timed out after 1000 milliseconds', new \GuzzleHttp\Psr7\Request('POST', 'http://example.test')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $api->setOverrideClient($client);

        try {
            $api->callWithBackstop('POST', 'fast-endpoint', [
                'timeout' => 5,
                'body'    => '{}',
                'headers' => [],
                'query'   => [],
            ], 30);

            $this->fail('Expected RequestException was not thrown');
        } catch (RequestException $exception) {
            $this->assertStringContainsString('cURL error 28', $exception->getMessage());
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertNull($exception->getResponse());
        }
    }

    /**
     * Confirms a real Guzzle ConnectException (connection refused/DNS failure, no
     * response ever received) reconstructs correctly across the fork boundary as a
     * ConnectException specifically (not a generic RequestException) — callers
     * distinguish the two (e.g. RetryableApiExceptionService treats ConnectException
     * as always API-retryable).
     */
    public function test_backstop_reconstructs_a_connect_exception(): void
    {
        $api = new BackstopTestApi;

        $mockHandler = new MockHandler([
            new ConnectException('Connection refused', new \GuzzleHttp\Psr7\Request('POST', 'http://example.test')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $api->setOverrideClient($client);

        try {
            $api->callWithBackstop('POST', 'fast-endpoint', [
                'timeout' => 5,
                'body'    => '{}',
                'headers' => [],
                'query'   => [],
            ], 30);

            $this->fail('Expected ConnectException was not thrown');
        } catch (ConnectException $exception) {
            $this->assertStringContainsString('Connection refused', $exception->getMessage());
        }
    }

    /**
     * Confirms a real Guzzle response-bearing failure (e.g. a 500) reconstructs as
     * the correct RequestException subtype with the response status/body intact —
     * proving the response-bearing reconstruction branch (RequestException::create())
     * derives the right subtype and preserves body content for callers that inspect
     * it (e.g. rate-limit body parsing, 4xx error-code extraction).
     */
    public function test_backstop_reconstructs_a_5xx_response_bearing_exception(): void
    {
        $api = new BackstopTestApi;

        $request     = new \GuzzleHttp\Psr7\Request('POST', 'http://example.test');
        $response    = new Response(503, ['X-Err' => 'yes'], '{"error":"unavailable"}');
        $mockHandler = new MockHandler([
            RequestException::create($request, $response),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $api->setOverrideClient($client);

        try {
            $api->callWithBackstop('POST', 'fast-endpoint', [
                'timeout' => 5,
                'body'    => '{}',
                'headers' => [],
                'query'   => [],
            ], 30);

            $this->fail('Expected RequestException was not thrown');
        } catch (RequestException $exception) {
            $this->assertNotNull($exception->getResponse());
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
            $this->assertSame('{"error":"unavailable"}', (string)$exception->getResponse()->getBody());
        }
    }

    /**
     * Confirms ApiTimeoutBackstopException is NOT retried within call()'s own
     * retry loop — deliberate design (see the exception's class docblock and
     * Api::call()'s dedicated catch branch): retrying it there would burn another
     * full timeout+buffer duration per attempt. Configures a real, non-zero
     * api_retry_count (which WOULD cause multiple attempts for an ordinary
     * RequestException/ConnectException) and proves requestWithTimeoutBackstop()
     * is invoked exactly ONCE regardless — fast and deterministic, no real
     * fork/network involved (see AlwaysBackstopTimesOutApi).
     */
    public function test_backstop_exception_is_not_retried_within_calls_own_retry_loop(): void
    {
        config(['danx.errors.api_retry_count' => 3]);

        // throttle() unconditionally checks the remote-provider-block registry via
        // Redis::ttl() before every call() — mock it "not blocked" (matches
        // ApiRateLimitBlockTest's pattern) since this test has no real Redis server.
        \Illuminate\Support\Facades\Redis::shouldReceive('ttl')->once()->andReturn(-2);

        $api = new AlwaysBackstopTimesOutApi;

        try {
            $api->post('endpoint', ['hello' => 'world']);
            $this->fail('Expected ApiTimeoutBackstopException was not thrown');
        } catch (ApiTimeoutBackstopException $exception) {
            $this->assertSame(1, $api->invocationCount, 'call() must not retry ApiTimeoutBackstopException at the API level even with api_retry_count > 0');
        }
    }

    /**
     * Confirms the backstop works correctly NESTED inside an already-forked
     * ProcessFork child — the real-world nesting concern this backstop must not
     * break: a ProcessFork-forked worker (e.g. identity/group extraction batch
     * fan-out) making its own outbound LLM call, which now also forks internally via
     * requestWithTimeoutBackstop(). Verifies (a) the hang is still caught inside the
     * nested fork, and (b) a sibling task in the SAME ProcessFork::run() batch that
     * does NOT hang completes normally and unaffected — proving the nested fork
     * doesn't corrupt or block its sibling.
     */
    public function test_backstop_works_nested_inside_a_process_fork_child(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run([
            // Task 0: hangs — real slow-server, curl timeout disabled, backstop should fire.
            function () {
                $api = new BackstopTestApi;

                try {
                    $api->callWithBackstop('POST', 'never-responds', [
                        'timeout' => 0,
                        'body'    => '{}',
                        'headers' => [],
                        'query'   => [],
                    ], 2);

                    return 'UNEXPECTED_NO_TIMEOUT';
                } catch (ApiTimeoutBackstopException $e) {
                    return 'backstop_fired';
                }
            },
            // Task 1: a normal, fast, unrelated task — proves the nested fork in
            // task 0 doesn't block or corrupt this sibling.
            function () {
                return 'sibling_completed';
            },
        ], maxConcurrent: 2);

        $this->assertCount(2, $results);
        $this->assertSame('success', $results[0]['status'], 'Task 0 result: ' . json_encode($results[0]));
        $this->assertSame('backstop_fired', $results[0]['result']);
        $this->assertSame('success', $results[1]['status']);
        $this->assertSame('sibling_completed', $results[1]['result']);
    }
}
