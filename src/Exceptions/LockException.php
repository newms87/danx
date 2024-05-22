<?php

namespace Newms87\Danx\Exceptions;

use Exception;
use Throwable;

class LockException extends Exception
{
	protected string $key;

	/**
	 * @param string|int     $key
	 * @param Throwable|null $previous
	 */
	public function __construct(string|int $key, Throwable $previous = null)
	{
		$this->key = $key;

		parent::__construct("Failed to acquire lock for $this->key", 401, $previous);
	}
}
