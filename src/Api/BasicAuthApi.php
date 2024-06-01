<?php

namespace Newms87\Danx\Api;

use Exception;
use Newms87\Danx\Exceptions\ApiException;

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
	 * @throws ApiException
	 */
	public function resolveToken(): ?string
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
	 * @throws Exception
	 */
	public function getRequestHeaders(): array
	{
		return [
				'Authorization' => "Basic " . $this->resolveToken(),
			] + parent::getRequestHeaders();
	}
}
