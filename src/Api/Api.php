<?php

namespace Newms87\Danx\Api;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Exceptions\ApiRequestException;
use Newms87\Danx\Exceptions\ApiTimeoutBackstopException;
use Newms87\Danx\Exceptions\RateLimitExceededException;
use Newms87\Danx\Helpers\ConsoleHelper;
use Newms87\Danx\Helpers\DateHelper;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Models\Audit\ApiLog;
use Newms87\Danx\Services\Error\RetryableErrorChecker;
use Newms87\Danx\Support\ProcessFork;
use Newms87\Danx\Traits\HasDebugLogging;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A generic implementation of an API
 */
abstract class Api
{
    use HasDebugLogging;

    // Limits the request rates to the API. Define as many rate limits as needed to satisfy requirements of endpoint
    // Leave empty for no rate limiting.
    // Set waitPerAttempt as false to throw an exception immediately if rate limit is exceeded
    protected array $rateLimits = [
        // ['limit' => 5, 'interval' => 1, 'waitPerAttempt' => .5], // 5 requests per second, wait 1/2 second between attempts
    ];

    // Default request timeout in seconds. Can be overridden in extending classes.
    protected int $requestTimeout = 60;

    // Extra seconds added on top of the resolved request timeout when arming the
    // fork+SIGKILL hard backstop (see requestWithTimeoutBackstop()). Deliberately
    // small and deliberately AFTER curl's own timeout — the backstop must only ever
    // engage once curl's own mechanism has already had its chance to fire.
    protected const int TIMEOUT_BACKSTOP_BUFFER_SECONDS = 5;

    // Opt-in switch for the fork-based timeout backstop (see requestWithTimeoutBackstop()).
    // Defaults OFF: forking every request has a real cost — fork()/DB reconnect/Redis+
    // Filesystem purge overhead on the hot path, AND it silently breaks any caller-side
    // Guzzle handler-stack middleware that mutates in-process PHP state (e.g. Guzzle's
    // own Middleware::history() — the request now genuinely executes in a separate,
    // disposable child process, so mutations to a PHP array/closure captured by
    // reference in that child are invisible to the parent once the child exits; this is
    // NOT a bug to work around, it's an unavoidable consequence of achieving a
    // gracefully-catchable recovery from a hang in a language that cannot interrupt a
    // blocking curl/stream read in-process — see requestWithTimeoutBackstop()'s docblock
    // for the empirical proof). The real, repeated production hang this backstop exists
    // to fix has been observed EXCLUSIVELY on OpenAI's /v1/responses endpoint (54
    // incidents, 0 occurrences on any other service this app calls) — so rather than
    // pay that cost universally for every API integration (S3, ConvertAPI, Mistral,
    // danxbot, ...), only the specific Api subclass(es) that have actually exhibited the
    // hang opt in by overriding this to true (see OpenAiApi).
    protected bool $timeoutBackstopEnabled = false;

    // Per-request timeout override. Set via setNextTimeout(), reset after each request.
    protected ?int $nextRequestTimeout = null;

    // Default retry count from config (or 0 if not configured).
    protected int $retryCount = 0;

    // Per-request retry count override. Set via retryCount(), reset after each request.
    protected ?int $nextRetryCount = null;

    // Per-request soft-timeout override. Set via setNextSoftTimeout(), consumed (read
    // + reset to null) at the very top of call(). Null (the default) means hedging is
    // disabled entirely — call() runs through executeCall() exactly as it did before
    // this feature existed. Non-null routes call() into callWithHedging() instead. See
    // callWithHedging()'s docblock for the full hedging mechanism.
    protected ?int $nextSoftTimeout = null;

    // Test-only seam: overrides the base Guzzle handler used when building hedge
    // attempts' async clients (see buildHedgeClient()). Unlike setOverrideClient() —
    // which replaces the ENTIRE Guzzle client, logging middleware included — this
    // handler still gets wrapped with the SAME handleRequest()/handleResponse()
    // middleware production hedging uses, so a test using this seam gets real ApiLog
    // rows (parent_api_log_id/attempt_number/is_hedge_winner included) exactly like
    // production, instead of silently bypassing ApiLog entirely the way
    // setOverrideClient() would for the hedging path.
    protected $overrideBaseHandler;

    // Hard cap on total attempts fired for one hedged call() invocation (1 initial +
    // up to 2 hedges). Never raise without also reconsidering ApiLog.attempt_number's
    // smallint range and the real cost multiplier this implies on the provider side.
    protected const int HEDGE_MAX_ATTEMPTS = 3;

    // After a winner is decided, how much additional bounded tick budget (in seconds)
    // is spent trying to let still-in-flight losers reach a real terminal state, so
    // their ApiLog row can be confidently marked is_hedge_winner=false instead of
    // being left ambiguous at null. Deliberately small relative to the soft timeout
    // itself — the entire point of hedging is to stop waiting on a slow attempt, so
    // this grace window must never approach the soft timeout duration or it defeats
    // hedging's own latency goal. 3s covers the common case of "the loser was actually
    // about to finish anyway" without materially extending the caller's total wait.
    protected const int HEDGE_LOSER_GRACE_SECONDS = 3;

    /**
     * Wall-clock source for hedging's scheduling decisions (soft-timeout thresholds,
     * retry-after delays, the loser grace window). Real microtime(true) in every
     * production call — this indirection exists purely so a test can substitute a
     * deterministic, controllable clock via a protected override instead of
     * depending on real elapsed wall-clock time racing against real sleep-driven tick
     * loops, which would otherwise make hedge-firing-threshold tests inherently
     * flaky. Never used by executeCall() or any pre-existing method.
     */
    protected function hedgeClockNow(): float
    {
        return microtime(true);
    }

    const string
        METHOD_DELETE  = 'DELETE',
        METHOD_GET     = 'GET',
        METHOD_OPTIONS = 'OPTIONS',
        METHOD_PATCH   = 'PATCH',
        METHOD_POST    = 'POST',
        METHOD_PUT     = 'PUT';

    const array METHODS = [
        self::METHOD_DELETE  => self::METHOD_DELETE,
        self::METHOD_GET     => self::METHOD_GET,
        self::METHOD_OPTIONS => self::METHOD_OPTIONS,
        self::METHOD_PATCH   => self::METHOD_PATCH,
        self::METHOD_POST    => self::METHOD_POST,
        self::METHOD_PUT     => self::METHOD_PUT,
    ];

    /** @var string The name of the service used in logging */
    public static string $serviceName;

    /** @var array An ordered list of requests made to API endpoints */
    public static array $requestLog = [];

    /** @var bool Enable request debug output via stream */
    protected bool $debug = false;

    // The URL Query params to send with the request
    protected array $queryParams = [];

    /** @var ResponseInterface */
    protected $response;

    protected string $rawContent = '';

    /** @var string The base URL to use for all API calls */
    protected string $baseApiUrl = '';

    /** @var string The prefix URI to use for all API requests */
    protected string $prefixUri;

    // The API log created for the currently running / most recently executed request
    protected ?ApiLog $currentApiLog = null;

    // The endpoint for the current request (stored before request for logging)
    protected ?string $currentEndpoint = null;

    /** @var array Registered callbacks for each GET request */
    protected array $onGetCallbacks = [];

    /** @var array Registered callbacks for each request that makes a modification (ie: POST, PUT, PATCH, DELETE) */
    protected array $onUpdateCallbacks = [];

    /**
     * Do Not call directly, use client() method instead
     *
     * @var Client
     */
    private $client;

    /** @var Client a temporary override for the client that will be reset after the next request */
    private $overrideClient;

    public static function make()
    {
        return new static;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @return ApiLog|null
     */
    public function getCurrentApiLog()
    {
        return $this->currentApiLog;
    }

    /**
     * Enable request debugging via PHP stream
     *
     * @param  bool  $status
     * @return $this
     */
    public function debug($status = true)
    {
        $this->debug = $status;

        return $this;
    }

    /**
     * Returns the base URL for all API requests
     *
     * @throws Exception
     */
    public function getBaseApiUrl(): string
    {
        if (!$this->baseApiUrl) {
            throw new Exception('Base API URL not set for ' . static::class . ' - please set in constructor or override getBaseApiUrl() method');
        }

        return $this->baseApiUrl;
    }

    /**
     * @return $this
     */
    public function setPrefixUri($uri)
    {
        $this->prefixUri = $uri;

        return $this;
    }

    /**
     * A Temporary override client that will be used instead of $client until after the next request.
     * This gets reset automatically when call() method completes
     *
     * @return $this
     */
    public function setOverrideClient(Client $client)
    {
        $this->overrideClient = $client;

        return $this;
    }

    public function getRequestHeaders()
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * The Redis key holding the remote provider block for this service.
     * The key's TTL is the data — its remaining lifetime is how long the
     * provider block lasts.
     */
    protected function getServiceBlockKey(): string
    {
        return 'api-remote-block:' . $this->getServiceName();
    }

    /**
     * Register a remote provider block for this service (e.g. after a 429 response).
     * Every caller, forked child, and retry sharing this Redis instance will then
     * fail fast in throttle() for the duration instead of re-hitting the blocked
     * provider with real HTTP calls.
     */
    public function registerServiceBlock(int $seconds): void
    {
        $seconds = max(1, $seconds);

        Redis::setex($this->getServiceBlockKey(), $seconds, 1);

        static::logWarning($this->getServiceName() . " API blocked by remote rate limit for {$seconds}s");
    }

    /**
     * Remaining seconds on this service's remote provider block, or 0 when not blocked.
     * Redis returns -2 (missing key) / -1 (no TTL) — both clamp to 0.
     */
    public function getServiceBlockTtl(): int
    {
        return max(0, (int)Redis::ttl($this->getServiceBlockKey()));
    }

    /**
     * Throttle requests (if $rateLimits is set) to avoid hitting rate limits.
     *
     * Before any local rate-limit accounting, checks the remote provider block
     * registry — a block registered by a prior 429 response throws immediately
     * WITHOUT making any HTTP call, so fan-out (forks/retries/other jobs) discovers
     * the block from Redis instead of hammering the blocked provider.
     *
     * @throws Exception
     */
    public function throttle(): void
    {
        $blockTtl = $this->getServiceBlockTtl();

        if ($blockTtl > 0) {
            throw new RateLimitExceededException(
                $this->getServiceName() . " API is blocked by remote rate limit (retry after {$blockTtl}s)",
                $blockTtl
            );
        }

        if ($this->rateLimits) {
            $serviceName = $this->getServiceName();

            foreach ($this->rateLimits as $rateLimit) {
                $limit          = $rateLimit['limit']          ?? null;
                $interval       = $rateLimit['interval']       ?? null;
                $waitPerAttempt = $rateLimit['waitPerAttempt'] ?? null;

                if ($limit && $interval) {
                    $key = $serviceName . '-' . $limit . '-' . $interval . '-limiter';

                    // Lua script for atomic increment and expiry.
                    $luaScript = <<<'LUA'
local current = redis.call("INCR", KEYS[1])
if tonumber(current) == 1 then
    redis.call("EXPIRE", KEYS[1], ARGV[1])
end
return current
LUA;

                    $maxWaitSeconds = (int)config('danx.errors.rate_limit_max_wait_seconds', 45);
                    $waitedSeconds  = 0;
                    $loggedWaiting  = false;

                    while (true) {
                        // Use the eval method with an array of arguments and specify the number of keys.
                        // Here, $key is our single key (hence 1) and $interval is passed as an argument for the expiry.
                        $current = Redis::eval($luaScript, 1, $key, $interval);

                        // If within rate limits, proceed.
                        if ($current <= $limit) {
                            break;
                        }

                        // If no wait time is set, throw an exception immediately.
                        if (!$waitPerAttempt) {
                            throw new ApiException("Rate limit exceeded for $serviceName: $limit requests per $interval second(s)");
                        }

                        // Fail fast instead of silently busy-waiting for the limiter key's full TTL
                        // (up to a full $interval seconds) — a caller running inside a job with a
                        // fixed timeout budget must never block here unbounded and unlogged.
                        if ($waitedSeconds >= $maxWaitSeconds) {
                            $retryAfterSeconds = max(1, (int)Redis::ttl($key));
                            static::logWarning("Rate limit wait exceeded {$maxWaitSeconds}s for $serviceName: $limit requests per $interval second(s), retry after {$retryAfterSeconds}s");

                            throw new RateLimitExceededException(
                                "Rate limit wait exceeded {$maxWaitSeconds}s for $serviceName: $limit requests per $interval second(s)",
                                $retryAfterSeconds
                            );
                        }

                        if (!$loggedWaiting) {
                            static::logDebug("Rate limit hit for $serviceName ($current/$limit per {$interval}s), waiting up to {$maxWaitSeconds}s for budget");
                            $loggedWaiting = true;
                        }

                        // Wait for the configured time (converted to microseconds) before trying again.
                        usleep($waitPerAttempt * 1000 * 1000);
                        $waitedSeconds += $waitPerAttempt;
                    }
                }
            }
        }
    }

    /**
     * @param  array  $options
     * @return Client
     *
     * @throws Exception
     */
    public function client($options = [])
    {
        // These options must be set per-request, not on the cached client
        $perRequestOptions = ['headers', 'base_uri', 'timeout'];
        foreach ($perRequestOptions as $option) {
            if (isset($options[$option])) {
                throw new Exception("Do not pass '$option' to client(). Use the appropriate method instead: " . match ($option) {
                    'headers'  => 'override getRequestHeaders()',
                    'base_uri' => 'set \$this->baseApiUrl',
                    'timeout'  => 'use setNextTimeout() or set \$this->requestTimeout',
                });
            }
        }

        if (!$this->client) {
            $options['handler'] = $this->createHandler();

            // Force cURL to use poll/select-based timeouts instead of SIGALRM.
            // This prevents conflicts with pcntl_alarm() used by Laravel's queue worker,
            // which would otherwise override cURL's SIGALRM and prevent timeouts from firing.
            // Note: Use + operator instead of array_merge() to preserve integer CURLOPT_* keys
            $options['curl'] = ($options['curl'] ?? []) + [CURLOPT_NOSIGNAL => true];

            $this->client = new Client($options);
        }

        return $this->overrideClient ?: $this->client;
    }

    protected function createHandler(): HandlerStack
    {
        // This just sets up a basic logging handler to push all requests / responses onto the requestLog array
        $callable = fn(callable $handler) => fn(
            RequestInterface $request,
            array $options = []
        ) => $this->handleRequest($handler, $request, $options);

        // Setup Logging
        $handlerStack = HandlerStack::create();
        $handlerStack->push($callable);

        return $handlerStack;
    }

    public function handleRequest(callable $handler, RequestInterface $request, array $options = [])
    {
        if (config('danx.audit.api.enabled')) {
            try {
                $timeout             = isset($options['timeout']) ? (int)$options['timeout'] : null;
                $this->currentApiLog = ApiLog::logRequest(
                    static::class,
                    $this->getServiceName(),
                    $request,
                    $timeout,
                    $this->currentEndpoint
                );
            } catch (Exception $exception) {
                static::logError(
                    'Failed committing API log request entry: ' . StringHelper::logSafeString($exception->getMessage()),
                    ['exception' => $exception]
                );
            }
        }

        return $handler($request, $options)->then(fn(ResponseInterface $response) => $this->handleResponse($request, $response));
    }

    public function handleResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        static::$requestLog[] = [
            'request'  => $request,
            'response' => $response,
        ];

        if (config('danx.audit.api.enabled')) {
            try {
                ApiLog::logResponse($this->currentApiLog, $response);
                $this->fireCallbacks($this->currentApiLog);
            } catch (Exception $exception) {
                static::logError(
                    'Failed committing API log request entry: ' . StringHelper::logSafeString($exception->getMessage()),
                    ['exception' => $exception]
                );
            }
        }

        return $response;
    }

    /**
     * @return $this
     */
    public function onGet(callable $callback)
    {
        $this->onGetCallbacks[] = $callback;

        return $this;
    }

    /**
     * @return $this
     */
    public function onUpdate(callable $callback)
    {
        $this->onUpdateCallbacks[] = $callback;

        return $this;
    }

    /**
     * Fires any registered callbacks
     */
    protected function fireCallbacks(ApiLog $apiLog)
    {
        if (method_exists($this, 'afterLog')) {
            $this->afterLog($apiLog);
        }

        if ($apiLog->method === self::METHOD_GET) {
            if ($this->onGetCallbacks) {
                foreach ($this->onGetCallbacks as $callback) {
                    try {
                        $callback($apiLog);
                    } catch (Exception $exception) {
                        static::logError(
                            'Error in GET callback: ' . $exception->getMessage(),
                            ['exception' => $exception]
                        );
                    }
                }
            }
        } else {
            if ($this->onUpdateCallbacks) {
                foreach ($this->onUpdateCallbacks as $callback) {
                    try {
                        $callback($apiLog);
                    } catch (Exception $exception) {
                        static::logError(
                            'Error in UPDATE callback: ' . $exception->getMessage(),
                            ['exception' => $exception]
                        );
                    }
                }
            }
        }
    }

    /**
     * Return a list of all requests, in a human readable format
     *
     * @return Collection
     */
    public static function getRequestLog(bool $formatted = true)
    {
        if ($formatted) {
            $entries = collect([]);

            foreach (self::$requestLog as $entry) {
                $entries->push(static::formatRequest($entry['request'], $entry['response'] ?? null));
            }

            return $entries;
        } else {
            return collect(self::$requestLog);
        }
    }

    /**
     * Outputs to the console a string formatted list of api requests / responses
     */
    public static function consoleRequestLog()
    {
        $entries = static::getRequestLog();

        foreach ($entries as $entry) {
            (new ConsoleHelper)->info("\n\n$entry\n\n");
        }
    }

    /**
     * Format a request and an optional response as a string that is easy to read
     *
     * @return string
     */
    public static function formatRequest(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        string $message = ''
    ) {
        $uri         = $request->getUri();
        $method      = $request->getMethod();
        $requestBody = (string)$request->getBody();
        $headers     = self::displayHeaders($request->getHeaders());

        if ($response) {
            $statusCode = $response->getStatusCode();

            $responseBody = (string)$response->getBody();

            // Always reset in this context in case someone else wants to read it
            $response->getBody()->rewind();
        } else {
            $statusCode   = 0;
            $responseBody = '';
        }

        $requestBody  = StringHelper::limitText(10000, StringHelper::safeConvertToUTF8($requestBody));
        $responseBody = StringHelper::limitText(10000, StringHelper::safeConvertToUTF8($responseBody));

        return "$method $statusCode $uri\n" .
            "$headers\n\n" .
            ($message ? $message . "\n\n" : '') .
            "Request:\n$requestBody\n\n" .
            "Response:\n$responseBody";
    }

    /**
     * Build a string representing the headers
     *
     * @return string
     */
    public static function displayHeaders($headers)
    {
        $str     = '';
        $headers = StringHelper::redactHeaders($headers);

        foreach ($headers as $key => $values) {
            $str .= "$key: " . implode(',', $values) . "\n";
        }

        return $str;
    }

    /**
     * @return string
     *
     * @throws Exception
     */
    public function getServiceName()
    {
        return static::$serviceName ?? 'unknown';
    }

    /**
     * @return static
     */
    public function queryParams($data)
    {
        $this->queryParams = $data;

        return $this;
    }

    /**
     * Set the timeout for the next request only.
     * This overrides the default requestTimeout for one request, then resets.
     */
    public function setNextTimeout(int $timeout): static
    {
        $this->nextRequestTimeout = $timeout;

        return $this;
    }

    /**
     * Arm hedged-request mode for the next request only. When set, call() fires a
     * duplicate request if the first hasn't returned within $seconds, races them, and
     * uses whichever completes first — see callWithHedging() for the full mechanism.
     * One-shot: consumed (read + reset to null) at the very top of call().
     */
    public function setNextSoftTimeout(int $seconds): static
    {
        $this->nextSoftTimeout = $seconds;

        return $this;
    }

    /**
     * Test-only seam — see $overrideBaseHandler's docblock for why this exists instead
     * of reusing setOverrideClient() for hedging tests.
     */
    public function setOverrideBaseHandler(?callable $handler): static
    {
        $this->overrideBaseHandler = $handler;

        return $this;
    }

    /**
     * Set the retry count for the next request only.
     * This overrides the default retryCount for one request, then resets.
     */
    public function retryCount(int $count): static
    {
        $this->nextRetryCount = $count;

        return $this;
    }

    /**
     * Get the effective retry count for the current request.
     * Returns per-request override if set, otherwise class default or config default.
     */
    protected function getEffectiveRetryCount(): int
    {
        if ($this->nextRetryCount !== null) {
            return $this->nextRetryCount;
        }

        if ($this->retryCount > 0) {
            return $this->retryCount;
        }

        return (int)config('danx.errors.api_retry_count', 0);
    }

    /**
     * Get the retry delay in milliseconds.
     */
    protected function getRetryDelayMs(): int
    {
        return (int)config('danx.errors.api_retry_delay_ms', 1000);
    }

    public function mergeQueryParamsFromUrl(string $url, array $queryParams = []): array
    {
        $uri = parse_url($url);

        if (isset($uri['query'])) {
            parse_str($uri['query'], $urlQueryParams);

            $queryParams = array_merge($urlQueryParams, $queryParams);
        }

        return $queryParams;
    }

    /**
     * Hard backstop against a hung outbound HTTP request that curl's own client-side
     * timeout (Guzzle's 'timeout' option, wired down to CURLOPT_TIMEOUT_MS) has, for
     * reasons not fully understood, occasionally failed to enforce — observed as a
     * silent, indefinite hang with zero response bytes and zero exception on real
     * outbound OpenAI calls in production. This is NOT a replacement for curl's own
     * timeout (still configured normally by the caller via $requestOptions['timeout']
     * below) — it is an independent safety net that only ever engages once curl's own
     * mechanism has already failed to do its job (armed for $timeoutSeconds + a small
     * buffer, always AFTER curl's own deadline).
     *
     * ## Why this is fork-based, not pcntl_alarm()-based (real, empirically-verified finding)
     *
     * The original design for this method was an in-process pcntl_alarm() + SIGALRM
     * handler that would throw ApiTimeoutBackstopException directly from the signal
     * handler. That does NOT work — verified empirically with three isolated
     * reproductions before this design was chosen:
     *
     *  1. pcntl_alarm()+pcntl_signal(SIGALRM,...) DOES correctly interrupt a plain
     *     PHP sleep() at the expected time (confirmed: fires at exactly the armed
     *     duration).
     *  2. The SAME mechanism does NOT interrupt a blocking Guzzle/curl request that
     *     has genuinely hung (Guzzle 'timeout' => 0 against a real TCP server that
     *     accepts the connection and never sends a byte back) — the registered
     *     SIGALRM handler is simply never invoked; the process hangs past the armed
     *     duration indefinitely.
     *  3. The SAME mechanism does NOT interrupt a blocking raw PHP fread() on a
     *     socket either (no curl/Guzzle involved at all) — ruling out "a curl-
     *     specific quirk" as the explanation.
     *
     * The consistent explanation across all three: PHP's async-signal dispatch only
     * invokes the registered userland callback once execution returns to the Zend
     * VM. sleep() is a PHP built-in that cooperatively checks for pending signals;
     * fread()/curl's internal poll-and-retry-on-EINTR loops are C-level code that
     * never hands control back to the VM while genuinely blocked — so the signal is
     * delivered at the OS level but the PHP-level handler that would throw our
     * exception is never actually reached. This is a real limitation of PHP's pcntl
     * extension against blocking stream/curl I/O, not a bug in a specific
     * implementation attempt.
     *
     * The one mechanism verified to actually work against a genuinely-hung blocking
     * call: an EXTERNAL process sending SIGKILL, which the kernel enforces
     * unconditionally regardless of what the target is blocked in (also verified
     * empirically: a forked watchdog sleeping 3s then SIGKILLing its parent
     * successfully terminated a parent hung in the exact same blocking Guzzle call
     * that the in-process alarm could not interrupt). This method therefore forks
     * the ACTUAL HTTP request into a child process and has the PARENT act as the
     * watchdog via a cooperative poll loop (pcntl_waitpid(WNOHANG) + usleep()) — the
     * parent is never itself blocked in curl, so it stays fully responsive and can
     * SIGKILL the child and throw a normal, catchable, loggable PHP exception the
     * instant the deadline is exceeded.
     *
     * ## Mechanics
     *
     * Follows the same fork hygiene ProcessFork::forkChild()/executeInChild()
     * establishes and documents in depth (DB::disconnect() before fork,
     * DB::reconnect() in both parent and child, Redis/Filesystem client purge before
     * fork) — a forked child inherits copies of the parent's open sockets, and
     * concurrent use of the same underlying connection by parent and child corrupts
     * it. The actual Guzzle Response (and any caught RequestException/ConnectException)
     * cannot cross the fork boundary as live objects — both wrap PSR-7 stream
     * resources, which do not survive serialize()/unserialize() (verified: a bare
     * resource becomes an unusable int). The child therefore flattens the outcome to
     * plain, serializable data (status/headers/body strings, or an exception
     * class+message+optional response) written to a temp file; the parent
     * reconstructs an equivalent Response — or re-throws an equivalent
     * RequestException/ConnectException via Guzzle's own factory, so the EXISTING
     * catch (RequestException|ConnectException) block in call() classifies/retries
     * it exactly as if no fork had happened — from that data.
     *
     * $this->currentApiLog is instance state set by handleRequest()/handleResponse()
     * (fired synchronously inside the Guzzle handler stack during $client->request())
     * — since the child gets its own COPY of $this on fork, the child's ApiLog write
     * is invisible to the parent's copy of $this unless explicitly propagated. The
     * child passes back its created ApiLog's id; the parent re-fetches it onto its
     * own $this->currentApiLog so callers (LlmService, AgentThreadService — both call
     * Api::getCurrentApiLog() after the request completes) see the real row.
     *
     * ## Skipped entirely inside an active DB transaction (empirically required)
     *
     * DB::disconnect() on EITHER side of the fork is not merely a local, per-process
     * file-descriptor close — it sends the database server a real protocol-level
     * termination for that (shared, until reconnect) session. An earlier attempt at
     * this method tried disconnecting ONLY in the child (to leave a parent-side
     * active transaction untouched) on the theory that POSIX's per-process file
     * descriptor tables would isolate the two sides; that theory was wrong in
     * practice — the child's reconnect tore down the connection for the PARENT too
     * ("server closed the connection unexpectedly" / "no connection to the server"
     * on the parent's very next query, reproduced against a real RefreshDatabase-
     * wrapped test). There is therefore no way to fork safely while a transaction
     * the caller still needs is open — this method checks DB::transactionLevel() and
     * falls back to a direct, unforked request (today's pre-backstop behavior) when
     * one is active, rather than risk corrupting it.
     */
    protected function requestWithTimeoutBackstop(Client $client, string $type, string $url, array $requestOptions, int $timeoutSeconds): ResponseInterface
    {
        if (!$this->timeoutBackstopEnabled) {
            // Not opted in (the default for every Api subclass) — behave exactly as
            // before this backstop existed: a plain, direct, unforked request. See
            // $timeoutBackstopEnabled's docblock for why this is opt-in, not universal.
            return $client->request($type, $url, $requestOptions);
        }

        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            // pcntl/posix not available on this platform/build (e.g. local Windows
            // dev, or a PHP build without these extensions) — fall back to relying
            // solely on curl's own configured timeout, exactly as before this
            // backstop existed. Never fatal, never blocks non-Linux dev environments.
            return $client->request($type, $url, $requestOptions);
        }

        if (DB::transactionLevel() > 0) {
            // Forking while inside an ACTIVE DB transaction is unsafe and must be
            // skipped — empirically verified (not theorized): a real DB-level
            // disconnect on either side of the fork (needed so the child can safely
            // get its OWN connection for its ApiLog writes) sends the database
            // server a genuine protocol-level termination for that shared session,
            // not just a local file-descriptor close — the OTHER side then fails
            // with "server closed the connection unexpectedly" / "no connection to
            // the server" on its very next query, because the session is gone
            // SERVER-SIDE, not just locally. An attempted fix that instead left the
            // parent's connection untouched and disconnected only in the child hit
            // this exact failure. This app's own standing rule already forbids
            // DB::transaction() in application code (see CLAUDE.md — rollback
            // deletes AuditRequest rows), so a transaction here is virtually always
            // a TEST harness's wrapper (e.g. RefreshDatabase) rather than production
            // traffic — falling back to a direct, unforked request preserves the
            // caller's transaction untouched at the cost of this one call not
            // having the backstop's protection, which matches this method's
            // pre-existing behavior before the backstop existed at all.
            return $client->request($type, $url, $requestOptions);
        }

        $backstopSeconds = max(1, $timeoutSeconds + self::TIMEOUT_BACKSTOP_BUFFER_SECONDS);
        $tempFile         = tempnam(sys_get_temp_dir(), 'api_backstop_');

        // Matches ProcessFork::forkChild()'s own established pattern exactly (disconnect
        // BEFORE forking, reconnect independently in BOTH parent and child afterward) —
        // see ProcessFork's class docblock for the full rationale. Forked processes
        // share the same underlying DB session/socket until one side reconnects; the
        // DB::transactionLevel() guard above is what makes this safe to do unconditionally
        // here (no active transaction survives a reconnect either side of the fork).
        DB::disconnect();
        ProcessFork::purgeAllRedisConnections();
        ProcessFork::purgeFilesystemDisks();

        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::reconnect();
            @unlink($tempFile);
            static::logWarning('pcntl_fork() failed while arming the timeout backstop — proceeding without it for this request');

            return $client->request($type, $url, $requestOptions);
        }

        if ($pid === 0) {
            // === CHILD: perform the actual request, flatten the outcome to plain
            // data, write it to the temp file, always exit(0) — never return to
            // the caller's code path.
            $this->executeBackstopChildRequest($client, $type, $url, $requestOptions, $tempFile);
            exit(0);
        }

        // === PARENT: never itself blocked in curl — free to poll and enforce the
        // deadline in real time.
        DB::reconnect();

        $status  = 0;
        $exited  = false;
        $deadlineAt = microtime(true) + $backstopSeconds;

        while (microtime(true) < $deadlineAt) {
            $waitResult = pcntl_waitpid($pid, $status, WNOHANG);

            if ($waitResult === $pid) {
                $exited = true;
                break;
            }

            usleep(50_000);
        }

        if (!$exited) {
            // Deadline exceeded and the child is STILL alive — this is the hang
            // this backstop exists to catch. SIGKILL cannot be caught, blocked, or
            // restarted by anything the child might be stuck in.
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            @unlink($tempFile);

            throw new ApiTimeoutBackstopException($this->getServiceName(), $type, $url, $backstopSeconds);
        }

        $outcome = $this->readBackstopChildOutcome($tempFile);

        if ($outcome === null) {
            // Child exited (possibly abnormally) but produced no readable result —
            // treat as an interrupted/failed attempt rather than silently
            // proceeding with nothing. Same failure family as a genuine hang.
            throw new ApiTimeoutBackstopException($this->getServiceName(), $type, $url, $backstopSeconds);
        }

        if ($outcome['api_log_id']) {
            $this->currentApiLog = ApiLog::find($outcome['api_log_id']);
        }

        if ($outcome['ok']) {
            $r = $outcome['response'];

            return new Psr7Response($r['status'], $r['headers'], $r['body'], $r['protocol'], $r['reason']);
        }

        throw $this->reconstructBackstopChildException($outcome['exception'], $type, $url);
    }

    /**
     * Runs INSIDE the forked child (see requestWithTimeoutBackstop()). Performs the
     * real Guzzle request, then flattens whatever happened (success or a Guzzle
     * exception) to plain serializable data written to $tempFile — Response/
     * RequestException/ConnectException objects wrap PSR-7 stream resources that do
     * not survive serialize() across the fork boundary.
     */
    protected function executeBackstopChildRequest(Client $client, string $type, string $url, array $requestOptions, string $tempFile): void
    {
        // Fresh DB/Redis/Filesystem connections for this child — same reasoning as
        // ProcessFork::executeInChild(). handleRequest()/handleResponse() write
        // ApiLog rows via the DB during $client->request() below, so this child
        // needs a working (non-corrupted, non-shared-with-parent) connection.
        DB::reconnect();
        ProcessFork::purgeAllRedisConnections();
        ProcessFork::purgeFilesystemDisks();

        try {
            $response = $client->request($type, $url, $requestOptions);

            $data = [
                'ok'         => true,
                'api_log_id' => $this->currentApiLog?->id,
                'response'   => [
                    'status'   => $response->getStatusCode(),
                    'reason'   => $response->getReasonPhrase(),
                    'headers'  => $response->getHeaders(),
                    'body'     => (string)$response->getBody(),
                    'protocol' => $response->getProtocolVersion(),
                ],
                'exception'  => null,
            ];
        } catch (RequestException|ConnectException $exception) {
            $response = $exception instanceof RequestException ? $exception->getResponse() : null;

            $data = [
                'ok'         => false,
                'api_log_id' => $this->currentApiLog?->id,
                'response'   => null,
                'exception'  => [
                    'class'    => get_class($exception),
                    'message'  => $exception->getMessage(),
                    'response' => $response ? [
                        'status'  => $response->getStatusCode(),
                        'reason'  => $response->getReasonPhrase(),
                        'headers' => $response->getHeaders(),
                        'body'    => (string)$response->getBody(),
                    ] : null,
                ],
            ];
        } catch (Throwable $exception) {
            // Anything outside Guzzle's own exception hierarchy — preserve the real
            // class/message rather than silently losing it, but still surface it
            // through the SAME RequestException reconstruction path in the parent
            // (see reconstructBackstopChildException()) so call()'s existing catch
            // block handles it uniformly.
            $data = [
                'ok'         => false,
                'api_log_id' => $this->currentApiLog?->id,
                'response'   => null,
                'exception'  => [
                    'class'    => get_class($exception),
                    'message'  => get_class($exception) . ': ' . $exception->getMessage(),
                    'response' => null,
                ],
            ];
        }

        $written = @file_put_contents($tempFile, serialize($data));

        if ($written === false) {
            // Nothing more we can do from inside the child — the parent's own
            // "no readable result" fallback (readBackstopChildOutcome() returning
            // null) covers this.
            exit(1);
        }
    }

    /**
     * Reads and unserializes the child's flattened outcome from requestWithTimeoutBackstop().
     * Returns null on any missing/corrupt/unreadable result — the caller treats that
     * the same as a hang rather than silently proceeding with nothing.
     */
    protected function readBackstopChildOutcome(string $tempFile): ?array
    {
        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            @unlink($tempFile);

            return null;
        }

        $serialized = file_get_contents($tempFile);
        @unlink($tempFile);

        $data = @unserialize($serialized);

        if (!is_array($data) || !array_key_exists('ok', $data)) {
            return null;
        }

        return $data;
    }

    /**
     * Reconstructs a real RequestException/ConnectException from the child's
     * flattened exception data, so call()'s EXISTING catch (RequestException|
     * ConnectException) block classifies/retries it exactly as if the request had
     * run in-process (unchanged isTimeoutException() regex match on the preserved
     * original message, unchanged 429/5xx/4xx handling via the reconstructed
     * response). Uses Guzzle's own RequestException::create() factory when a
     * response is present so the correct subtype (ClientException/ServerException)
     * is derived exactly as Guzzle itself would.
     */
    protected function reconstructBackstopChildException(array $exceptionData, string $type, string $url): RequestException|ConnectException
    {
        $request = new Psr7Request($type, $url);

        if ($exceptionData['response']) {
            $r        = $exceptionData['response'];
            $response = new Psr7Response($r['status'], $r['headers'], $r['body'], reason: $r['reason']);

            // RequestException::create() derives its own message from the response
            // status/reason (matching real Guzzle behavior for a response-bearing
            // failure exactly) rather than accepting a custom one — the ORIGINAL
            // child-side message is only load-bearing for the no-response branch
            // below, where isTimeoutException()'s regex needs the real wording.
            return RequestException::create($request, $response);
        }

        if (is_a($exceptionData['class'], ConnectException::class, true)) {
            return new ConnectException($exceptionData['message'], $request);
        }

        return new RequestException($exceptionData['message'], $request);
    }

    /**
     * Make a request to the endpoint with automatic retry for transient failures.
     *
     * Retry behavior is controlled by:
     * - config('danx.errors.api_retry_count') - default retry count
     * - config('danx.errors.api_retry_delay_ms') - delay between retries
     * - config('danx.errors.api_retryable_checker') - service to determine if error is retryable
     *
     * Hedging: when setNextSoftTimeout() was called before this request, control
     * branches into callWithHedging() instead — see that method's docblock. When no
     * soft timeout is set (the default, for every caller that never calls
     * setNextSoftTimeout()), this method does nothing but delegate to executeCall(),
     * which is this method's ENTIRE pre-hedging implementation, unchanged.
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     * @throws Exception
     */
    public function call(string $type, string $endpoint, $body = '', array $options = []): static
    {
        $softTimeout            = $this->nextSoftTimeout;
        $this->nextSoftTimeout = null;

        if ($softTimeout === null) {
            return $this->executeCall($type, $endpoint, $body, $options);
        }

        return $this->callWithHedging($type, $endpoint, $body, $options, $softTimeout);
    }

    /**
     * The non-hedged request implementation — byte-for-byte call()'s ENTIRE body
     * before hedging existed. Every caller that never calls setNextSoftTimeout() is
     * provably unaffected by the hedging feature because this method is untouched.
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     * @throws Exception
     */
    protected function executeCall(string $type, string $endpoint, $body = '', array $options = []): static
    {
        if (!is_string($body)) {
            $jsonBody = StringHelper::safeJsonEncode($body);

            if ($body && !$jsonBody) {
                throw new ApiException("Failed to encode body to JSON\n\n" . serialize($body));
            }

            $body = $jsonBody;
        }

        $this->throttle();

        $this->response = null;

        // Store endpoint for API logging (used by handleRequest)
        $this->currentEndpoint = $endpoint;

        // Reset the query params
        $queryParams       = $this->queryParams;
        $this->queryParams = [];

        // Capture and reset per-request timeout, tracking the source for debugging
        $timeoutSource            = $this->nextRequestTimeout !== null ? 'setNextTimeout()' : 'requestTimeout';
        $timeout                  = $this->nextRequestTimeout ?? $this->requestTimeout;
        $this->nextRequestTimeout = null;

        // Capture and reset per-request retry count
        $maxRetries           = $this->getEffectiveRetryCount();
        $this->nextRetryCount = null;

        $client = $this->client();

        // Be sure to reset a temporarily overridden client
        $this->overrideClient = null;

        // Enable request debugging
        if ($this->debug) {
            $options['debug'] = true;
        }

        // Apply per-request options (not cached on client)
        $options['timeout'] = $options['timeout'] ?? $timeout;
        $options['headers'] = ($options['headers'] ?? []) + $this->getRequestHeaders();

        // Build full URL with base URI and prefix
        $baseUrl = $this->baseApiUrl ?: $this->getBaseApiUrl();
        $url     = rtrim($baseUrl, '/') . '/' . (!empty($this->prefixUri) ? rtrim($this->prefixUri, '/') . '/' : '') . $endpoint;

        $queryParams = $this->mergeQueryParamsFromUrl($url, $queryParams);

        $requestOptions = $options + [
            'query' => $queryParams,
            'body'  => $body,
        ];

        // Retry loop for transient failures
        $attempt       = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $startTime = microtime(true);

                // Log request start with timeout source for debugging
                $retryInfo = $maxRetries > 0 ? " attempt={$attempt}/" . ($maxRetries + 1) : '';
                static::logDebug("Request started: {$type} {$url} timeout={$timeout}s (from {$timeoutSource}){$retryInfo}");

                $this->response = $this->requestWithTimeoutBackstop($client, $type, $url, $requestOptions, $timeout);

                // Log successful completion with timing and size
                $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);
                $size      = $this->response->getBody()->getSize() ?? strlen($this->response->getBody()->getContents());
                $this->response->getBody()->rewind();
                static::logDebug('Response (' . FileHelper::getHumanSize($size) . ' in ' . DateHelper::formatDuration($elapsedMs) . "): {$type} " . $this->response->getStatusCode() . " {$url}");

                return $this;
            } catch (ApiTimeoutBackstopException $exception) {
                $elapsed = round(microtime(true) - $startTime, 3);

                // Deliberately NOT retried within this same call() invocation — by
                // definition this exception only fires after the FULL configured
                // timeout (+ buffer) already elapsed once, so an API-level retry loop
                // would burn another full timeout duration per attempt, compounding
                // delay exactly backwards from the point of tightening timeouts in
                // the first place. Same reasoning RetryableJobExceptionService
                // documents for cURL-error-28 timeouts: retried at the JOB level
                // (a fresh attempt, backed off, observable) not the API level.
                $errorType     = 'timeout_backstop';
                $lastException = $exception;

                $retryInfo = $maxRetries > 0 ? " attempt={$attempt}/" . ($maxRetries + 1) : '';
                static::logError(
                    "Request FORCE-INTERRUPTED by fork+SIGKILL timeout backstop: {$type} {$url} elapsed={$elapsed}s " .
                    "configured_timeout={$timeout}s (from {$timeoutSource}){$retryInfo} — curl's own timeout did not fire; " .
                    'this call is now failing loudly instead of hanging indefinitely.',
                    ['exception' => $exception]
                );

                if ($this->currentApiLog) {
                    ApiLog::logResponseError($this->currentApiLog, $exception, $errorType);
                }

                // Not retryable at the API level (see reasoning above) — always break
                // out of the retry loop and let the exception propagate to the caller,
                // where job-level retry classification (RetryableJobExceptionService)
                // takes over.
                break;
            } catch (RequestException|ConnectException $exception) {
                $elapsed   = round(microtime(true) - $startTime, 3);
                $isTimeout = $this->isTimeoutException($exception);
                $errorType = $isTimeout ? 'timeout' : ($exception instanceof ConnectException ? 'connection_error' : 'request_error');

                $errorResponse = $exception instanceof RequestException ? $exception->getResponse() : null;

                if ($errorResponse && $errorResponse->getStatusCode() === 429) {
                    // Remote provider rate limit — register a shared block so every
                    // other caller/fork/retry fails fast in throttle() instead of
                    // re-hitting the blocked provider with real HTTP calls.
                    $blockSeconds = $this->resolveRateLimitBlockSeconds($errorResponse);
                    $this->registerServiceBlock($blockSeconds);

                    $bodySnippet = StringHelper::limitText(500, StringHelper::safeConvertToUTF8((string)$errorResponse->getBody()));
                    $errorResponse->getBody()->rewind();

                    $errorType     = 'rate_limit';
                    $lastException = new RateLimitExceededException(
                        $this->getServiceName() . " API returned 429 (blocked for {$blockSeconds}s): $bodySnippet",
                        $blockSeconds,
                        $exception
                    );
                } else {
                    // Wrap in ApiRequestException for consistent error handling
                    $message = $isTimeout
                        ? "Request timed out after {$timeout}s (from {$timeoutSource})"
                        : ($exception instanceof ConnectException ? 'Connection failed' : '');
                    $lastException = new ApiRequestException($this->getServiceName(), $exception, $message);
                }

                // Log the error
                static::logWarning("Request failed: {$type} {$url} elapsed={$elapsed}s is_timeout=" . ($isTimeout ? 'true' : 'false') . " timeout={$timeout}s (from {$timeoutSource})");

                if ($this->currentApiLog) {
                    ApiLog::logResponseError($this->currentApiLog, $exception, $errorType);
                }

                $hasRetriesRemaining = $attempt <= $maxRetries;

                // Rate-limit responses bypass the generic retryable check: short blocks
                // are waited out inline (OpenAI-style throttle recovery), long blocks are
                // thrown upward immediately so the job layer can defer instead of sleeping.
                if ($lastException instanceof RateLimitExceededException) {
                    $inlineWaitMax = (int)config('danx.errors.rate_limit_inline_wait_max_seconds', 30);

                    if ($hasRetriesRemaining && $lastException->retryAfterSeconds <= $inlineWaitMax) {
                        static::logDebug("[RATE LIMITED] Waiting {$lastException->retryAfterSeconds}s inline before retry (attempt {$attempt}/{$maxRetries})");
                        sleep($lastException->retryAfterSeconds);

                        continue;
                    }

                    break;
                }

                // Check if we should retry
                $isRetryable = RetryableErrorChecker::isApiRetryable($lastException);

                if ($hasRetriesRemaining && $isRetryable) {
                    $delayMs = $this->getRetryDelayMs();
                    static::logDebug("[RETRYABLE] Will retry in {$delayMs}ms (attempt {$attempt}/{$maxRetries}): " . StringHelper::limitText(200, $lastException->getMessage()));
                    usleep($delayMs * 1000);

                    continue;
                }

                // No more retries or not retryable - throw
                break;
            }
        }

        throw $lastException;
    }

    /**
     * Hedged-request implementation (SG-hedging feature). Fires attempt 1 immediately;
     * if it hasn't settled by $softTimeoutSeconds, fires attempt 2 without cancelling
     * attempt 1; if NEITHER has settled by 2x $softTimeoutSeconds, fires attempt 3
     * (HEDGE_MAX_ATTEMPTS caps the total at 3). Races every in-flight attempt and
     * returns as soon as ANY one succeeds. Losing attempts are never cancelled — they
     * are given a small bounded grace window (HEDGE_LOSER_GRACE_SECONDS) to reach a
     * real terminal state (so their ApiLog row can be marked is_hedge_winner=false
     * instead of left ambiguous), then discarded regardless of whether they finished.
     *
     * ## Why this can't reuse Utils::any()/Utils::some()->wait()
     *
     * Verified against this repo's actual vendored guzzlehttp/promises source:
     * Promise::invokeWaitList() (the wait function every aggregate promise from
     * Utils::any()/some() is built on) walks EVERY promise in its derivation chain in
     * declaration order and calls waitIfPending() on each unconditionally, even after
     * the aggregate has already resolved. If a later entry in that chain is still
     * genuinely pending, blocking on THAT entry's own wait function stalls the caller
     * past the point the race was actually decided — exactly the head-of-line problem
     * hedging exists to avoid. Instead, each attempt is fired via requestAsync() and
     * driven forward by repeatedly calling ITS OWN transport's tick() (see
     * tickHedgeTransport()) — real production traffic ticks a genuine
     * GuzzleHttp\Handler\CurlMultiHandler; hedging tests may substitute
     * setOverrideBaseHandler() with a test double, which is ticked the same way.
     *
     * ## Why every attempt gets its own cloned Api instance
     *
     * $this->currentApiLog (and $this->response, and the lazily-built $this->client)
     * are single-instance mutable state written by handleRequest()/handleResponse() —
     * sharing one Api instance across concurrent in-flight attempts would race those
     * writes. Each attempt instead runs against `clone $this` (see fireHedgeAttempt()),
     * which — unlike resolving a fresh instance via the container — naturally carries
     * over already-configured instance state (baseApiUrl overrides, prefixUri, a
     * test's setOverrideBaseHandler()) while still guaranteeing independent mutable
     * per-attempt state, since clone gives every attempt its own copy of every
     * property and the request-scoped ones are explicitly reset immediately after.
     * $timeoutBackstopEnabled is forced off on every hedge attempt clone: the fork+
     * SIGKILL backstop (OpenAiApi only) would duplicate a sibling hedge attempt's live
     * socket into its forked child if both fired concurrently — the soft timeout
     * itself already provides earlier, cheaper hang mitigation for hedge-eligible
     * calls, making the backstop redundant here even where it's otherwise opted in.
     *
     * ## Retry-on-transient-failure within one hedge attempt
     *
     * getEffectiveRetryCount()/getRetryDelayMs()/RetryableErrorChecker::isApiRetryable()/
     * resolveRateLimitBlockSeconds()/registerServiceBlock() — the SAME decision logic
     * executeCall()'s retry loop uses — are reused unchanged (see
     * handleHedgeAttemptFailure()). Only the CONTROL FLOW differs: executeCall()'s
     * blocking while-loop-with-usleep() cannot be reused verbatim inside a hedge
     * attempt without blocking sibling attempts, so a retry is instead scheduled as a
     * non-blocking "retryAt" timestamp serviced by the same tick loop driving every
     * other attempt (see tickHedgeAttempt()). throttle() is called exactly once per
     * fired hedge attempt (fireHedgeAttempt()), never per internal retry — matching
     * executeCall(), where throttle() is called once per call() invocation, outside
     * its own retry loop.
     */
    protected function callWithHedging(string $type, string $endpoint, $body = '', array $options = [], int $softTimeoutSeconds = 0): static
    {
        $plan      = $this->buildRequestPlan($type, $endpoint, $body, $options);
        $startedAt = $this->hedgeClockNow();

        $attempts             = [$this->fireHedgeAttempt($plan, 1, null, $startedAt)];
        $firedCount           = 1;
        $nextAttemptDeadline  = $startedAt + $softTimeoutSeconds;
        $fireFailures         = [];

        while (true) {
            foreach ($attempts as $index => $attempt) {
                if (!$attempt['done']) {
                    $attempts[$index] = $this->tickHedgeAttempt($attempt, $plan);
                }
            }

            $winnerIndex = $this->findHedgeWinnerIndex($attempts);

            if ($winnerIndex !== null) {
                $winner = $attempts[$winnerIndex];
                unset($attempts[$winnerIndex]);

                $this->finalizeLosingHedgeAttempts($attempts);

                return $this->applyHedgeWinner($winner);
            }

            $allFailedSoFar = $this->allHedgeAttemptsFailed($attempts);

            if ($allFailedSoFar && $firedCount >= self::HEDGE_MAX_ATTEMPTS) {
                throw $this->buildHedgeFailureException($attempts, $fireFailures);
            }

            $now = $this->hedgeClockNow();

            // Fire the next hedge attempt at its scheduled soft-timeout threshold —
            // OR immediately (without waiting for the threshold) if every attempt
            // fired so far has already permanently failed, since there is no reason
            // to keep waiting on a schedule built around "still might come back".
            if ($firedCount < self::HEDGE_MAX_ATTEMPTS && ($now >= $nextAttemptDeadline || $allFailedSoFar)) {
                $firedCount++;
                $parentApiLogId = end($attempts)['apiLogId'] ?? null;

                try {
                    $attempts[] = $this->fireHedgeAttempt($plan, $firedCount, $parentApiLogId, $startedAt);
                } catch (Throwable $exception) {
                    $fireFailures[] = $exception;
                    static::logWarning("Hedge attempt {$firedCount} failed to fire: " . StringHelper::logSafeString($exception->getMessage()));
                }

                $nextAttemptDeadline = $startedAt + ($firedCount * $softTimeoutSeconds);
            }

            usleep(20_000);
        }
    }

    /**
     * Builds the (URL, request options, timeout, retry budget) plan shared identically
     * by every hedge attempt — computed ONCE from $this (the orchestrating instance)
     * before any attempt fires, so all attempts are genuinely duplicate requests. This
     * necessarily duplicates URL/query/header/timeout-resolution logic already present
     * in executeCall() — that duplication is deliberate: executeCall() must remain
     * byte-for-byte unchanged (see its own docblock), so it cannot be refactored to
     * share this helper without altering the no-hedging code path a diff reviewer
     * needs to see as untouched.
     */
    protected function buildRequestPlan(string $type, string $endpoint, $body, array $options): array
    {
        if (!is_string($body)) {
            $jsonBody = StringHelper::safeJsonEncode($body);

            if ($body && !$jsonBody) {
                throw new ApiException("Failed to encode body to JSON\n\n" . serialize($body));
            }

            $body = $jsonBody;
        }

        $queryParams        = $this->queryParams;
        $this->queryParams = [];

        $timeout                   = $this->nextRequestTimeout ?? $this->requestTimeout;
        $this->nextRequestTimeout = null;

        $maxRetries            = $this->getEffectiveRetryCount();
        $this->nextRetryCount = null;

        if ($this->debug) {
            $options['debug'] = true;
        }

        $options['timeout'] = $options['timeout'] ?? $timeout;
        $options['headers'] = ($options['headers'] ?? []) + $this->getRequestHeaders();

        $baseUrl = $this->baseApiUrl ?: $this->getBaseApiUrl();
        $url     = rtrim($baseUrl, '/') . '/' . (!empty($this->prefixUri) ? rtrim($this->prefixUri, '/') . '/' : '') . $endpoint;

        $queryParams = $this->mergeQueryParamsFromUrl($url, $queryParams);

        $requestOptions = $options + [
            'query' => $queryParams,
            'body'  => $body,
        ];

        return [
            'type'           => $type,
            'endpoint'       => $endpoint,
            'url'            => $url,
            'requestOptions' => $requestOptions,
            'timeout'        => $timeout,
            'maxRetries'     => $maxRetries,
        ];
    }

    /**
     * Builds an async-capable Guzzle Client for one hedge attempt, wired through the
     * SAME handleRequest()/handleResponse() logging middleware executeCall() uses, so
     * ApiLog rows are written identically to a real (non-hedged) request. Returns the
     * Client plus (when not using a test's setOverrideBaseHandler()) the concrete
     * CurlMultiHandler instance so the caller can drive it via tick() — nothing else
     * exposes that instance once it's buried inside a HandlerStack.
     *
     * @return array{0: Client, 1: ?CurlMultiHandler}
     */
    protected function buildHedgeClient(): array
    {
        $curlMulti   = null;
        $baseHandler = $this->overrideBaseHandler;

        if (!$baseHandler) {
            $curlMulti   = new CurlMultiHandler();
            $baseHandler = $curlMulti;
        }

        $callable = fn(callable $handler) => fn(RequestInterface $request, array $options = []) => $this->handleRequest($handler, $request, $options);

        $stack = HandlerStack::create($baseHandler);
        $stack->push($callable);

        $client = new Client([
            'handler' => $stack,
            // Matches client()'s own reasoning exactly (see its comment) — forces
            // cURL to use poll/select-based timeouts instead of SIGALRM so this
            // doesn't conflict with pcntl_alarm()-based mechanisms elsewhere.
            'curl'    => [CURLOPT_NOSIGNAL => true],
        ]);

        return [$client, $curlMulti];
    }

    /**
     * Advances one attempt's async transport by one increment. Real production
     * traffic always has a genuine CurlMultiHandler; a test using
     * setOverrideBaseHandler() may supply a handler exposing its own tick() (duck-
     * typed, not a formal interface — this stays a test-only concern) to control
     * settlement timing deterministically without real I/O or real sleeping. Either
     * way, GuzzleHttp\Promise\Utils::queue()->run() is run at least once so any
     * already-settled promise's queued .then() callback (our own handleResponse()
     * included) actually fires — CurlMultiHandler::tick() already does this
     * internally, but running it again is idempotent and guarantees progress even
     * when neither branch above applies.
     */
    protected function tickHedgeTransport(?CurlMultiHandler $curlMulti, $baseHandler): void
    {
        if ($curlMulti) {
            $curlMulti->tick();
        } elseif ($baseHandler && is_object($baseHandler) && method_exists($baseHandler, 'tick')) {
            $baseHandler->tick();
        }

        PromiseUtils::queue()->run();
    }

    /**
     * Fires one hedge attempt: clones $this into an isolated instance, builds its
     * async client, consumes throttle() exactly once, and sends the request. Throws
     * (uncaught) if throttle() itself throws — callWithHedging() lets that propagate
     * immediately for attempt 1 (nothing else is in flight yet, matching
     * executeCall()'s own immediate-throw behavior) and catches it for attempts 2/3
     * (recorded as a fire failure; other already-in-flight attempts keep racing).
     */
    protected function fireHedgeAttempt(array $plan, int $attemptNumber, ?int $parentApiLogId, float $startedAt): array
    {
        $attemptApi = clone $this;

        // Reset every piece of request-scoped mutable state a clone would otherwise
        // carry over — each attempt must start from a genuinely clean slate even
        // though it inherits $this's configured instance state (baseApiUrl override,
        // prefixUri, overrideBaseHandler, debug flag, etc.) via the clone.
        $attemptApi->currentApiLog          = null;
        $attemptApi->response               = null;
        $attemptApi->timeoutBackstopEnabled = false;
        $attemptApi->nextRequestTimeout     = null;
        $attemptApi->nextRetryCount         = null;
        $attemptApi->nextSoftTimeout        = null;
        $attemptApi->queryParams            = [];
        $attemptApi->client                 = null;
        $attemptApi->overrideClient         = null;
        $attemptApi->currentEndpoint        = $plan['endpoint'];

        [$client, $curlMulti] = $attemptApi->buildHedgeClient();

        $attempt = [
            'api'            => $attemptApi,
            'client'         => $client,
            'curlMulti'      => $curlMulti,
            'baseHandler'    => $attemptApi->overrideBaseHandler,
            'promise'        => null,
            'attemptNumber'  => $attemptNumber,
            'parentApiLogId' => $parentApiLogId,
            'apiLogId'       => null,
            'retriesUsed'    => 0,
            'maxRetries'     => $plan['maxRetries'],
            'retryAt'        => null,
            'done'           => false,
            'failed'         => false,
            'response'       => null,
            'lastException'  => null,
            'startedAt'      => $startedAt,
        ];

        $attemptApi->throttle();

        return $this->sendHedgeAttemptRequest($attempt, $plan);
    }

    /**
     * Sends (or re-sends, for an internal retry) the actual HTTP request for one
     * hedge attempt and stamps the resulting ApiLog row's hedge-chain fields.
     * attempt_number=1/parent_api_log_id=null are the column DEFAULTS — deliberately
     * never touched here for attempt 1, so an ordinary (non-hedged) call's ApiLog rows
     * are never at risk of this code path accidentally reaching them (it can't:
     * executeCall() never calls this method at all).
     */
    protected function sendHedgeAttemptRequest(array $attempt, array $plan): array
    {
        /** @var static $attemptApi */
        $attemptApi = $attempt['api'];
        $tryNumber  = $attempt['retriesUsed'] + 1;

        static::logDebug("Hedge attempt {$attempt['attemptNumber']} (try {$tryNumber}) started: {$plan['type']} {$plan['url']} timeout={$plan['timeout']}s [hedged]");

        $attempt['promise'] = $attempt['client']->requestAsync($plan['type'], $plan['url'], $plan['requestOptions']);

        $apiLog              = $attemptApi->getCurrentApiLog();
        $attempt['apiLogId'] = $apiLog?->id;

        if ($attempt['attemptNumber'] > 1 && $apiLog) {
            $apiLog->update([
                'parent_api_log_id' => $attempt['parentApiLogId'],
                'attempt_number'    => $attempt['attemptNumber'],
            ]);
        }

        return $attempt;
    }

    /**
     * Advances one in-flight (not yet done) attempt by one tick: services a pending
     * scheduled retry, otherwise pumps its transport and inspects its promise's
     * settled state. Never blocks — never calls ->wait() while a promise is PENDING.
     */
    protected function tickHedgeAttempt(array $attempt, array $plan): array
    {
        if ($attempt['retryAt'] !== null) {
            if ($this->hedgeClockNow() < $attempt['retryAt']) {
                return $attempt;
            }

            $attempt['retryAt'] = null;
            $attempt['retriesUsed']++;

            return $this->sendHedgeAttemptRequest($attempt, $plan);
        }

        $this->tickHedgeTransport($attempt['curlMulti'], $attempt['baseHandler']);

        if ($attempt['promise']->getState() === PromiseInterface::PENDING) {
            return $attempt;
        }

        try {
            // Safe: only reached once getState() is no longer PENDING, so this never
            // blocks — for a FULFILLED promise, wait() returns the value immediately.
            $attempt['response'] = $attempt['promise']->wait();
            $attempt['done']     = true;
        } catch (Throwable $reason) {
            $attempt = $this->handleHedgeAttemptFailure($attempt, $plan, $reason);
        }

        return $attempt;
    }

    /**
     * Classifies one hedge attempt's failed HTTP try and either schedules a
     * non-blocking retry (transient/retryable, budget remaining) or marks the attempt
     * permanently failed. Reuses executeCall()'s own decision-making methods
     * (resolveRateLimitBlockSeconds()/registerServiceBlock()/getRetryDelayMs()/
     * RetryableErrorChecker::isApiRetryable()) unchanged — only the scheduling
     * mechanism (a timestamp serviced by the shared tick loop, instead of a blocking
     * sleep()) differs, because a blocking sleep() here would stall sibling attempts
     * still racing concurrently.
     */
    protected function handleHedgeAttemptFailure(array $attempt, array $plan, Throwable $reason): array
    {
        /** @var static $attemptApi */
        $attemptApi = $attempt['api'];
        $apiLog     = $attemptApi->getCurrentApiLog();

        if (!$reason instanceof RequestException && !$reason instanceof ConnectException) {
            // Outside Guzzle's own exception hierarchy — executeCall()'s catch scope
            // only classifies RequestException|ConnectException too; anything else is
            // not retryable here either, matching that same scope.
            if ($apiLog) {
                ApiLog::logResponseError($apiLog, $reason, 'request_error');
            }

            $attempt['done']          = true;
            $attempt['failed']        = true;
            $attempt['lastException'] = $reason;

            return $attempt;
        }

        $isTimeout     = $this->isTimeoutException($reason);
        $errorType     = $isTimeout ? 'timeout' : ($reason instanceof ConnectException ? 'connection_error' : 'request_error');
        $errorResponse = $reason instanceof RequestException ? $reason->getResponse() : null;

        $hasRetriesRemaining = $attempt['retriesUsed'] < $attempt['maxRetries'];

        if ($errorResponse && $errorResponse->getStatusCode() === 429) {
            $blockSeconds = $attemptApi->resolveRateLimitBlockSeconds($errorResponse);
            $attemptApi->registerServiceBlock($blockSeconds);

            if ($apiLog) {
                ApiLog::logResponseError($apiLog, $reason, 'rate_limit');
            }

            $lastException = new RateLimitExceededException(
                $attemptApi->getServiceName() . " API returned 429 (blocked for {$blockSeconds}s) [hedge attempt {$attempt['attemptNumber']}]",
                $blockSeconds,
                $reason
            );

            $inlineWaitMax = (int)config('danx.errors.rate_limit_inline_wait_max_seconds', 30);

            if ($hasRetriesRemaining && $blockSeconds <= $inlineWaitMax) {
                $attempt['retryAt']       = $this->hedgeClockNow() + $blockSeconds;
                $attempt['lastException'] = $lastException;

                return $attempt;
            }

            $attempt['done']          = true;
            $attempt['failed']        = true;
            $attempt['lastException'] = $lastException;

            return $attempt;
        }

        $message = $isTimeout
            ? "Request timed out after {$plan['timeout']}s [hedge attempt {$attempt['attemptNumber']}]"
            : ($reason instanceof ConnectException ? 'Connection failed' : '');
        $lastException = new ApiRequestException($attemptApi->getServiceName(), $reason, $message);

        if ($apiLog) {
            ApiLog::logResponseError($apiLog, $reason, $errorType);
        }

        $isRetryable = RetryableErrorChecker::isApiRetryable($lastException);

        if ($hasRetriesRemaining && $isRetryable) {
            $delayMs                  = $attemptApi->getRetryDelayMs();
            $attempt['retryAt']       = $this->hedgeClockNow() + ($delayMs / 1000);
            $attempt['lastException'] = $lastException;

            return $attempt;
        }

        $attempt['done']          = true;
        $attempt['failed']        = true;
        $attempt['lastException'] = $lastException;

        return $attempt;
    }

    /**
     * @return int|null Index into $attempts of the first attempt that has genuinely
     *                   succeeded (done and not failed), or null if none has yet.
     */
    protected function findHedgeWinnerIndex(array $attempts): ?int
    {
        foreach ($attempts as $index => $attempt) {
            if ($attempt['done'] && !$attempt['failed']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * True only when every currently-fired attempt has reached a permanent (non-
     * retryable-or-exhausted) failure. An empty $attempts array is never "all failed"
     * (nothing has been tried).
     */
    protected function allHedgeAttemptsFailed(array $attempts): bool
    {
        if (!$attempts) {
            return false;
        }

        foreach ($attempts as $attempt) {
            if (!$attempt['done'] || !$attempt['failed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every fired attempt permanently failed and the hedge cap was reached — surface
     * the most informative failure available: the last attempt's own exception, else
     * (only possible if every attempt failed to even FIRE, e.g. throttle() rejecting
     * every one) the last fire-time exception, else a generic ApiException.
     */
    protected function buildHedgeFailureException(array $attempts, array $fireFailures): Throwable
    {
        $last = end($attempts);

        if ($last && $last['lastException']) {
            return $last['lastException'];
        }

        if ($fireFailures) {
            return end($fireFailures);
        }

        return new ApiException($this->getServiceName() . ': all hedged attempts failed with no recorded exception');
    }

    /**
     * Spends HEDGE_LOSER_GRACE_SECONDS (bounded, never indefinite) continuing to drive
     * every losing attempt still in flight, purely so its ApiLog row can reach a real
     * terminal state before being marked is_hedge_winner=false. Deliberately does NOT
     * service a losing attempt's own pending retryAt — initiating a fresh HTTP retry
     * for a result about to be discarded would spend real provider cost for nothing;
     * a loser mid-retry-delay when the winner is picked simply stays ambiguous
     * (is_hedge_winner left null) rather than being pushed toward a resolution we no
     * longer need. Whether or not every loser finishes within the grace window,
     * callWithHedging() returns the winner's result either way once this returns.
     */
    protected function finalizeLosingHedgeAttempts(array $attempts): void
    {
        if (!$attempts) {
            return;
        }

        $graceDeadline = $this->hedgeClockNow() + self::HEDGE_LOSER_GRACE_SECONDS;

        while ($this->hedgeClockNow() < $graceDeadline) {
            $stillPending = false;

            foreach ($attempts as $index => $attempt) {
                if ($attempt['done'] || $attempt['retryAt'] !== null) {
                    continue;
                }

                $this->tickHedgeTransport($attempt['curlMulti'], $attempt['baseHandler']);

                if ($attempt['promise']->getState() === PromiseInterface::PENDING) {
                    $stillPending = true;

                    continue;
                }

                try {
                    $attempt['response'] = $attempt['promise']->wait();
                    $attempt['done']     = true;
                } catch (Throwable $reason) {
                    /** @var static $attemptApi */
                    $attemptApi = $attempt['api'];
                    $apiLog     = $attemptApi->getCurrentApiLog();

                    if ($apiLog) {
                        ApiLog::logResponseError($apiLog, $reason, 'request_error');
                    }

                    $attempt['done']   = true;
                    $attempt['failed'] = true;
                }

                $attempts[$index] = $attempt;
            }

            if (!$stillPending) {
                break;
            }

            usleep(20_000);
        }

        foreach ($attempts as $attempt) {
            $this->markHedgeLoserOutcome($attempt);
        }
    }

    /**
     * Marks a losing attempt's ApiLog row is_hedge_winner=false ONLY when we actually
     * observed it reach a terminal state (success-but-not-first, or a real failure) —
     * a definitively-known non-winner. An attempt still pending when the grace window
     * closes is genuinely ambiguous (we never learned what it would have done) and is
     * deliberately left untouched (column stays null), per this feature's spec.
     */
    protected function markHedgeLoserOutcome(array $attempt): void
    {
        if (!$attempt['done']) {
            return;
        }

        /** @var static $attemptApi */
        $attemptApi = $attempt['api'];

        $attemptApi->getCurrentApiLog()?->update(['is_hedge_winner' => false]);
    }

    /**
     * Applies the winning hedge attempt's outcome onto $this (the orchestrating
     * instance callers hold a reference to), matching executeCall()'s own contract —
     * $this->response and $this->currentApiLog set, $this returned — so callers of
     * call()/post()/get()/etc. see identical behavior whether or not hedging fired.
     */
    protected function applyHedgeWinner(array $winner): static
    {
        /** @var static $winnerApi */
        $winnerApi = $winner['api'];

        $winnerApi->getCurrentApiLog()?->update(['is_hedge_winner' => true]);

        $this->response      = $winner['response'];
        $this->currentApiLog = $winnerApi->getCurrentApiLog();

        $elapsedMs = (int)round(($this->hedgeClockNow() - $winner['startedAt']) * 1000);
        static::logDebug("Hedge winner: attempt {$winner['attemptNumber']} status=" . $this->response->getStatusCode() . " elapsed={$elapsedMs}ms");

        return $this;
    }

    /**
     * Make a GET request
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     */
    public function get(string $endpoint, array $query = [], string|array $data = '', array $options = []): static
    {
        return $this->queryParams($query)->call(self::METHOD_GET, $endpoint, $data, $options);
    }

    /**
     * Make a POST request
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     */
    public function post(string $endpoint, string|array $data = [], $options = []): static
    {
        return $this->call(self::METHOD_POST, $endpoint, $data, $options);
    }

    /**
     * Make a PUT request
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     */
    public function put(string $endpoint, string|array $data = [], array $options = []): static
    {
        return $this->call(self::METHOD_PUT, $endpoint, $data, $options);
    }

    /**
     * Make a PATCH request
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     */
    public function patch(string $endpoint, string|array $data = [], array $options = []): static
    {
        return $this->call(self::METHOD_PATCH, $endpoint, $data, $options);
    }

    /**
     * Make a DELETE request
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     */
    public function delete(string $endpoint, string|array $data = [], array $options = []): static
    {
        return $this->call(self::METHOD_DELETE, $endpoint, $data, $options);
    }

    /**
     * Return the JSON response as an associative array
     */
    public function json(?string $key = null): float|int|bool|array|string|null
    {
        if (!$this->response) {
            return null;
        }

        $this->rawContent = $this->response->getBody()->getContents();

        if (!$this->rawContent) {
            return null;
        }

        $json = json_decode($this->rawContent, true);

        if ($key) {
            return $json[$key] ?? null;
        }

        return $json;
    }

    public function getRawContent()
    {
        return $this->rawContent;
    }

    /**
     * Resolve how long a 429 response should block this service, in priority order:
     * integer Retry-After response header → per-API parseRateLimitBlockSeconds()
     * override → configured default.
     */
    protected function resolveRateLimitBlockSeconds(ResponseInterface $response): int
    {
        $retryAfter = $response->getHeaderLine('Retry-After');

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return max(1, (int)$retryAfter);
        }

        return $this->parseRateLimitBlockSeconds($response)
            ?? (int)config('danx.errors.rate_limit_block_default_seconds', 60);
    }

    /**
     * Per-API override hook: parse a provider-specific block duration (in seconds)
     * out of a 429 response — e.g. a "You are Blocked for 1 Hour" body message —
     * when the provider doesn't send a Retry-After header. Return null to fall back
     * to the configured default.
     */
    protected function parseRateLimitBlockSeconds(ResponseInterface $response): ?int
    {
        return null;
    }

    /**
     * Check if an exception is a timeout error
     */
    private function isTimeoutException(RequestException|ConnectException $exception): bool
    {
        // Check for timeout indicators in the exception message
        return preg_match('/(time out|timed out|timeout|cURL error 28)/', $exception->getMessage()) === 1;
    }
}
