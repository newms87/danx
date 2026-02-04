<?php

namespace Newms87\Danx\Api;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Newms87\Danx\Traits\HasDebugLogging;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Exceptions\ApiRequestException;
use Newms87\Danx\Services\Error\RetryableErrorChecker;
use Newms87\Danx\Helpers\ConsoleHelper;
use Newms87\Danx\Helpers\DateHelper;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Models\Audit\ApiLog;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    // Per-request timeout override. Set via setNextTimeout(), reset after each request.
    protected ?int $nextRequestTimeout = null;

    // Default retry count from config (or 0 if not configured).
    protected int $retryCount = 0;

    // Per-request retry count override. Set via retryCount(), reset after each request.
    protected ?int $nextRetryCount = null;

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
     * Throttle requests (if $rateLimits is set) to avoid hitting rate limits
     *
     * @throws Exception
     */
    public function throttle(): void
    {
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

                        // Wait for the configured time (converted to microseconds) before trying again.
                        usleep($waitPerAttempt * 1000 * 1000);
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
                    $timeout
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
        $str = '';

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

        return (int) config('danx.errors.api_retry_count', 0);
    }

    /**
     * Get the retry delay in milliseconds.
     */
    protected function getRetryDelayMs(): int
    {
        return (int) config('danx.errors.api_retry_delay_ms', 1000);
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
     * Make a request to the endpoint with automatic retry for transient failures.
     *
     * Retry behavior is controlled by:
     * - config('danx.errors.api_retry_count') - default retry count
     * - config('danx.errors.api_retry_delay_ms') - delay between retries
     * - config('danx.errors.api_retryable_checker') - service to determine if error is retryable
     *
     * @throws ApiException
     * @throws ApiRequestException
     * @throws GuzzleException
     * @throws Exception
     */
    public function call(string $type, string $endpoint, $body = '', array $options = []): static
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

        // Reset the query params
        $queryParams       = $this->queryParams;
        $this->queryParams = [];

        // Capture and reset per-request timeout, tracking the source for debugging
        $timeoutSource = $this->nextRequestTimeout !== null ? 'setNextTimeout()' : 'requestTimeout';
        $timeout       = $this->nextRequestTimeout ?? $this->requestTimeout;
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

                $this->response = $client->request($type, $url, $requestOptions);

                // Log successful completion with timing and size
                $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);
                $size = $this->response->getBody()->getSize() ?? strlen($this->response->getBody()->getContents());
                $this->response->getBody()->rewind();
                static::logDebug("Response (" . FileHelper::getHumanSize($size) . " in " . DateHelper::formatDuration($elapsedMs) . "): {$type} " . $this->response->getStatusCode() . " {$url}");

                return $this;
            } catch (RequestException|ConnectException $exception) {
                $elapsed   = round(microtime(true) - $startTime, 3);
                $isTimeout = $this->isTimeoutException($exception);
                $errorType = $isTimeout ? 'timeout' : ($exception instanceof ConnectException ? 'connection_error' : 'request_error');

                // Wrap in ApiRequestException for consistent error handling
                $message = $isTimeout
                    ? "Request timed out after {$timeout}s (from {$timeoutSource})"
                    : ($exception instanceof ConnectException ? 'Connection failed' : '');
                $lastException = new ApiRequestException($this->getServiceName(), $exception, $message);

                // Log the error
                static::logWarning("Request failed: {$type} {$url} elapsed={$elapsed}s is_timeout=" . ($isTimeout ? 'true' : 'false') . " timeout={$timeout}s (from {$timeoutSource})");

                if ($this->currentApiLog) {
                    ApiLog::logResponseError($this->currentApiLog, $exception, $errorType);
                }

                // Check if we should retry
                $hasRetriesRemaining = $attempt <= $maxRetries;
                $isRetryable         = RetryableErrorChecker::isApiRetryable($lastException);

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
     * Check if an exception is a timeout error
     */
    private function isTimeoutException(RequestException|ConnectException $exception): bool
    {
        // Check for timeout indicators in the exception message
        return preg_match('/(time out|timed out|timeout|cURL error 28)/', $exception->getMessage()) === 1;
    }
}
