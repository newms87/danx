<?php

namespace Newms87\DanxLaravel\Api;

use Exception;
use Newms87\DanxLaravel\Exceptions\ApiException;
use GuzzleHttp\Client;

/**
 * A generic implementation of an API authenticated via BasicAuth
 */
abstract class BasicAuthApi extends Api
{
	/** @var string|null Client ID or Key for the API */
	protected ?string $clientId = null;
	/** @var string|null Client Secret Key for the API */
	protected ?string $clientSecret = null;

	/**
	 * @param array $options
	 * @return Client
	 *
	 * @throws Exception
	 */
	public function client($options = [])
	{
		// Merge the Basic Auth Headers with any existing headers on the options
		$options['headers'] = ($options['headers'] ?? []) + $this->getBasicAuthHeaders();

		return parent::client($options);
	}

	/**
	 * @return string|null
	 * @throws ApiException
	 */
	public function resolveToken()
	{
		if ($this->clientId && $this->clientSecret) {
			return base64_encode($this->clientId . ':' . $this->clientSecret);
		} elseif ($this->clientId) {
			return $this->clientId;
		} elseif ($this->clientSecret) {
			return $this->clientSecret;
		} else {
			throw new ApiException('No client ID or client Secret set for ' . static::class);
		}
	}

	/**
	 * Returns the Basic Auth headers w/ client ID and client Secret
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function getBasicAuthHeaders()
	{
		$token = $this->resolveToken();

		return [
			'Authorization' => "Basic $token",
		];
	}
}
