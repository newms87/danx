<?php

namespace Newms87\DanxLaravel\Exceptions;

use Exception;

/**
 * This is a generic ValidationError that expects to render a client side message to the User.
 * Used for handling data validation exceptions, such as incorrect user input, etc.
 */
class ValidationError extends Exception
{
	public static int $level = 300;

	public function isClientSafe()
	{
		return true;
	}
}
