<?php

namespace Newms87\Danx\Api;

use Exception;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Exceptions\ApiRequestException;
use Newms87\Danx\Helpers\ConsoleHelper;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Models\Audit\ApiLog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A generic implementation of an API
 */
abstract class Api
{
	/** @var false Define the attempts per time period this API should be limited to.
	 *             (eg: THROTTLE_ATTEMPTS = 5, THROTTLE_DECAY_SECONDS = 1 --- equals 5 requests per second)
	 */
	const bool|int THROTTLE_ATTEMPTS      = false;
	const int      THROTTLE_DECAY_SECONDS = 1;

	const string
		METHOD_DELETE = 'DELETE',
		METHOD_GET = 'GET',
		METHOD_OPTIONS = 'OPTIONS',
		METHOD_PATCH = 'PATCH',
		METHOD_POST = 'POST',
		METHOD_PUT = 'PUT';

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

	//The URL Query params to send with the request
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
		return new static();
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
	 * @param bool $status
	 * @return $this
	 */
	public function debug($status = true)
	{
		$this->debug = $status;

		return $this;
	}

	/**
	 * Returns the base URL for all API requests
	 * @return string
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
	 * @param $uri
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
	 * @param Client $client
	 * @return $this
	 */
	public function setOverrideClient(Client $client)
	{
		$this->overrideClient = $client;

		return $this;
	}

	/**
	 * Throttle requests (if THROTTLE_ATTEMPTS is set) to avoid hitting rate limits
	 * NOTE: uses defined consts THROTTLE_ATTEMPTS and THROTTLE_DECAY_SECONDS
	 *
	 * @throws Exception
	 */
	public function throttle()
	{
		if (static::THROTTLE_ATTEMPTS) {
			$waitPerAttempt = static::THROTTLE_DECAY_SECONDS * 1000 / static::THROTTLE_ATTEMPTS;

			$key = $this->getServiceName();

			// As soon as the rate timer is up, we can take our turn
			while(RateLimiter::remaining($key . '-limiter', static::THROTTLE_ATTEMPTS) <= 0) {
				usleep($waitPerAttempt * 1000);
			}
			RateLimiter::hit($key . '-limiter', static::THROTTLE_DECAY_SECONDS);
		}
	}

	/**
	 * @param array $options
	 * @return Client
	 *
	 * @throws Exception
	 */
	public function client($options = [])
	{
		if (!$this->client) {
			$options['handler'] = $this->createHandler();

			$options['headers'] = ($options['headers'] ?? []) + [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				];

			$options['base_uri'] = $this->baseApiUrl ?: $this->getBaseApiUrl();

			$this->client = new Client($options);
		}

		return $this->overrideClient ?: $this->client;
	}

	/**
	 * @return HandlerStack
	 */
	protected function createHandler()
	{
		// This just sets up a basic logging handler to push all requests / responses onto the requestLog array
		$callable = fn(callable $handler) => fn(
			RequestInterface $request,
			array            $options = []
		) => $handler($request, $options)->then(
			function (ResponseInterface $response) use ($request): ResponseInterface {
				static::$requestLog[] = [
					'request'  => $request,
					'response' => $response,
				];

				if (config('danx.audit.api.enabled')) {
					try {
						$this->currentApiLog = ApiLog::logRequest(
							static::class,
							app(static::class)->getServiceName(),
							$request,
							$response
						);

						$this->fireCallbacks($this->currentApiLog);
					} catch(Exception $exception) {
						Log::error(
							'Failed committing API log request entry: ' . StringHelper::logSafeString($exception->getMessage()),
							['exception' => $exception]
						);
					}
				}

				return $response;
			}
		);

		// Setup Logging
		$handler = HandlerStack::create();
		$handler->push($callable);

		return $handler;
	}

	/**
	 * @param callable $callback
	 * @return $this
	 */
	public function onGet(callable $callback)
	{
		$this->onGetCallbacks[] = $callback;

		return $this;
	}

	/**
	 * @param callable $callback
	 * @return $this
	 */
	public function onUpdate(callable $callback)
	{
		$this->onUpdateCallbacks[] = $callback;

		return $this;
	}

	/**
	 * Fires any registered callbacks
	 *
	 * @param ApiLog $apiLog
	 */
	protected function fireCallbacks(ApiLog $apiLog)
	{
		if (method_exists($this, 'afterLog')) {
			$this->afterLog($apiLog);
		}

		if ($apiLog->method === self::METHOD_GET) {
			if ($this->onGetCallbacks) {
				foreach($this->onGetCallbacks as $callback) {
					try {
						$callback($apiLog);
					} catch(Exception $exception) {
						Log::error(
							'Error in GET callback for ' . static::class . ': ' . $exception->getMessage(),
							['exception' => $exception]
						);
					}
				}
			}
		} else {
			if ($this->onUpdateCallbacks) {
				foreach($this->onUpdateCallbacks as $callback) {
					try {
						$callback($apiLog);
					} catch(Exception $exception) {
						Log::error(
							'Error in UPDATE callback for ' . static::class . ': ' . $exception->getMessage(),
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
	 * @param bool $formatted
	 * @return Collection
	 */
	public static function getRequestLog(bool $formatted = true)
	{
		if ($formatted) {
			$entries = collect([]);

			foreach(self::$requestLog as $entry) {
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

		foreach($entries as $entry) {
			(new ConsoleHelper)->info("\n\n$entry\n\n");
		}
	}

	/**
	 * Format a request and an optional response as a string that is easy to read
	 *
	 * @param RequestInterface       $request
	 * @param ResponseInterface|null $response
	 * @param string                 $message
	 * @return string
	 */
	public static function formatRequest(
		RequestInterface  $request,
		ResponseInterface $response = null,
		string            $message = ''
	)
	{
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

		return "$method $statusCode $uri\n" .
			"$headers\n\n" .
			($message ? $message . "\n\n" : '') .
			"Request:\n$requestBody\n\n" .
			"Response:\n$responseBody";
	}

	/**
	 * Build a string representing the headers
	 *
	 * @param $headers
	 * @return string
	 */
	public static function displayHeaders($headers)
	{
		$str = '';

		foreach($headers as $key => $values) {
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
	 * @param $data
	 * @return static
	 */
	public function queryParams($data)
	{
		$this->queryParams = $data;

		return $this;
	}

	/**
	 * @param string $type
	 * @param string $endpoint
	 * @param string $body
	 * @param array  $options
	 * @return static
	 *
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
	public function call($type, $endpoint, $body = '', $options = [])
	{
		$this->throttle();

		$this->response = null;

		// Reset the query params
		$queryParams       = $this->queryParams;
		$this->queryParams = [];

		$client = $this->client();

		// Be sure to reset a temporarily overridden client
		$this->overrideClient = null;

		try {
			// Enable request debugging
			if ($this->debug) {
				$options['debug'] = true;
			}

			$url = (!empty($this->prefixUri) ? rtrim($this->prefixUri, '/') . '/' : '') . $endpoint;

			$this->response = $client->request(
				$type,
				$url,
				$options + [
					'query' => $queryParams,
					'body'  => $body,
				]
			);
		} catch(RequestException $exception) {
			throw new ApiRequestException(
				$this->getServiceName(),
				$exception,
				'',
				$this->currentApiLog
			);
		}

		return $this;
	}

	/**
	 * @param        $endpoint
	 * @param array  $query
	 * @param string $data
	 * @param array  $options
	 * @return static
	 *
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 */
	public function get($endpoint, $query = [], $data = '', $options = [])
	{
		return $this->queryParams($query)->call(self::METHOD_GET, $endpoint, $data ? json_encode($data) : '', $options);
	}

	/**
	 * @param       $endpoint
	 * @param array $data
	 * @param array $options
	 * @return static
	 *
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface|ApiException
	 */
	public function post($endpoint, $data = [], $options = [])
	{
		$jsonBody = StringHelper::safeJsonEncode($data);

		if ($data && !$jsonBody) {
			throw new ApiException("Failed to encode data to JSON\n\n" . serialize($data));
		}

		return $this->call(self::METHOD_POST, $endpoint, $jsonBody, $options);
	}

	/**
	 * @param       $endpoint
	 * @param array $data
	 * @param array $options
	 * @return $this
	 *
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 */
	public function put($endpoint, $data = [], $options = [])
	{
		return $this->call(self::METHOD_PUT, $endpoint, json_encode($data), $options);
	}

	/**
	 * @param       $endpoint
	 * @param array $data
	 * @param array $options
	 * @return $this
	 *
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 */
	public function patch($endpoint, $data = [], $options = [])
	{
		return $this->call(self::METHOD_PATCH, $endpoint, json_encode($data), $options);
	}

	/**
	 * Return the JSON response as an associative array
	 *
	 * @param string|null $key
	 * @return array|string|int|float|bool|null
	 */
	public function json(string $key = null): float|int|bool|array|string|null
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
}
