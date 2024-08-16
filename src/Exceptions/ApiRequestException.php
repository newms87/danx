<?php

namespace Newms87\Danx\Exceptions;

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

    protected ResponseInterface $response;

	public function __construct(
		$apiName,
		RequestException $exception,
		$message = "",
	)
	{
		$request  = $exception->getRequest();
		$response = $exception->getResponse();

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
			Api::formatRequest($request, $exception->getResponse(), $message);

		parent::__construct($message, $exception->getCode(), $exception);
	}

    public function getStatusCode(): int
    {
        return $this->response?->getStatusCode() ?? 0;
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
