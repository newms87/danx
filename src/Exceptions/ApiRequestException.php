<?php

namespace Newms87\Danx\Exceptions;

use GuzzleHttp\Exception\ConnectException;
use Newms87\Danx\Api\Api;
use Newms87\Danx\Helpers\StringHelper;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ApiRequestException extends \Exception
{
	/** @var string $apiName The name of the implementing API */
	protected $apiName;

	/** @var string contents The response body contents */
	protected $contents;

	/** @var array json If the response is json format, the json decoded response */
	protected $json;

	/** @var array $queryParams */
	protected $queryParams;

	/** @var string $requestContents The string contents in the body of the request */
	protected $requestContents;

	/** @var array $requestJson The associative array representing the JSON formatted contents of the request body (null if not JSON format) */
	protected $requestJson;

    protected ?ResponseInterface $response = null;

	public function __construct(
		$apiName,
		RequestException|ConnectException $exception,
		$message = "",
	)
	{
		$request  = $exception->getRequest();
		$response = $exception instanceof RequestException ? $exception->getResponse() : null;

		$this->apiName = $apiName;

		if ($response) {
			$this->contents = (string)$response->getBody();
			$this->json     = StringHelper::parseJson($this->contents);
            $this->response = $response;

			$response->getBody()->rewind();
		}

		$this->requestContents = (string)$request->getBody();
		$this->requestJson     = StringHelper::parseJson($this->requestContents);

		$messageTitle = "$apiName API Request Failed";

		$message = $messageTitle . "\n" .
			Api::formatRequest($request, $response, $message);

		parent::__construct($message, $exception->getCode(), $exception);
	}

    public function getStatusCode(): int
    {
        return $this->response?->getStatusCode() ?? 0;
    }

    /**
     * Return the first value of the named response header, or null when the
     * header is absent or no response is attached. Header names are matched
     * case-insensitively per the PSR-7 contract.
     */
    public function getResponseHeader(string $name): ?string
    {
        if (!$this->response) {
            return null;
        }

        $line = $this->response->getHeaderLine($name);

        return $line === '' ? null : $line;
    }

    /**
     * A short, safe sentence describing why this request failed — fit for a customer-facing
     * screen (SG-815). It deliberately carries none of what {@see self::getMessage()} does:
     * no headers, no request body (so never the prompt/config that body carries), no raw
     * response dump. `getMessage()` stays exactly as it was — the full formatted
     * request+response {@see Api::formatRequest()} composes — because internal consumers
     * (application logs, `ErrorLog`, `AuditRequest.logs`, `debug:replay-api-log`) depend on
     * that full detail for real debugging. This method is the ONE place that turns this
     * exception into a sentence for a reader who is not an engineer.
     *
     * Preference order, so this never returns an empty string:
     *
     * 1. The upstream response's own stated reason — {@see self::getJson()}'s `error.message`
     *    (OpenAI's own shape: `{"error":{"message":...}}`) or a bare `error`/`message` key
     *    (other common API shapes). This is "what actually went wrong", verified against a
     *    real production failure (SG-815, WR-1123: OpenAI returned
     *    `{"error":{"message":"Invalid schema for response_format '...'; ... 'phone' is not a
     *    valid format.", ...}}`, which this branch extracts correctly).
     * 2. A short slice of the raw response body, when it did not parse as JSON at all.
     * 3. A bare "<api> request failed[, with status <code>]." when the response carried no
     *    usable text (e.g. a connection failure with no response).
     */
    public function getUserFacingReason(): string
    {
        $reason = $this->reasonFromJson($this->json) ?? $this->reasonFromContents($this->contents);
        $api    = $this->apiName ?: 'The API';

        if ($reason !== null) {
            return self::limitReason("$api request failed: $reason");
        }

        $status = $this->getStatusCode();

        return $status > 0
            ? "$api request failed with status $status."
            : "$api request failed.";
    }

    /**
     * The upstream reason from a decoded JSON response body, trying the shapes real APIs
     * actually use: OpenAI's nested `error.message`, then a bare `error` or `message` string.
     * Never returns an array/object — a structured `error` that is itself an array (some APIs
     * nest validation details there) is not a sentence, so it is treated as "no reason found"
     * rather than serialized.
     */
    private function reasonFromJson(?array $json): ?string
    {
        if (!$json) {
            return null;
        }

        $candidate = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? null;

        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        return self::normalizeWhitespace($candidate);
    }

    /** The raw response body, normalized, when it carried text but did not parse as JSON. */
    private function reasonFromContents(?string $contents): ?string
    {
        if (!$contents || trim($contents) === '') {
            return null;
        }

        return self::normalizeWhitespace(StringHelper::safeConvertToUTF8($contents));
    }

    /** Collapse the literal `\n`/`\r`/tab runs a JSON-decoded string can still carry into single spaces. */
    private static function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private static function limitReason(string $value, int $max = 400): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }

	/**
	 * @return string The implementing API name
	 */
	public function getApiName(): string
	{
		return $this->apiName;
	}

	/**
	 * @return string The body contents as a string from the response
	 */
	public function getContents(): string
	{
		return $this->contents;
	}

	/**
	 * @return array|null The body contents as an associative array from the response if it was in JSON format.
	 *                    Will return null if it was not JSON format or the body was empty
	 */
	public function getJson(): ?array
	{
		return $this->json;
	}

	/**
	 * The string body for the request
	 *
	 * @return mixed|string
	 */
	public function getRequestContents(): mixed
	{
		return $this->requestContents;
	}

	/**
	 * The associative array representing the JSON formatted request body (null if not JSON format)
	 *
	 * @return array|mixed
	 */
	public function getRequestJson(): mixed
	{
		return $this->requestJson;
	}

	/**
	 * @return Throwable
	 */
	public function getRequestException(): Throwable
	{
		return $this->getPrevious();
	}
}
