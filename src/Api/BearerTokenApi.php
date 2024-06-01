<?php

namespace Newms87\Danx\Api;

use Exception;

/**
 * A generic implementation of an API authenticated via Authorization Bearer Tokens
 */
abstract class BearerTokenApi extends Api
{
	protected ?string $token = null;

	/**
	 * Returns the Bearer Token headers
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function getRequestHeaders(): array
	{
		if (!$this->token) {
			throw new Exception('API Token should be configured in child classes constructor');
		}

		return [
				'Authorization' => 'Bearer ' . $this->token,
			] + parent::getRequestHeaders();
	}
}
