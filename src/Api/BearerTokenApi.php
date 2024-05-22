<?php

namespace Newms87\DanxLaravel\Api;

use Exception;
use GuzzleHttp\Client;

/**
 * A generic implementation of an API authenticated via Authorization Bearer Tokens
 */
abstract class BearerTokenApi extends Api
{
	protected ?string $token = null;

	/**
	 * @param array $options
	 * @return Client
	 *
	 * @throws Exception
	 */
	public function client($options = [])
	{
		if (!$this->token) {
			throw new Exception('API Token should be configured in child classes constructor');
		}

		// Merge the auth headers with any existing headers on the options
		$options['headers'] = ($options['headers'] ?? []) + $this->getHeaders();

		return parent::client($options);
	}

	/**
	 * Returns the Bearer Token headers
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function getHeaders()
	{
		return [
			'Authorization' => 'Bearer ' . $this->token,
		];
	}
}
