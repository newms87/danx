<?php

namespace Newms87\DanxLaravel\Exceptions;

use Newms87\DanxLaravel\Input\Input;
use Illuminate\Validation\ValidationException;

class InputValidationException extends ValidationException
{
	/* @var Input */
	protected $input;

	public function __construct(Input $input, $response = null, $errorBag = 'default')
	{
		parent::__construct($input->getValidator(), $response, $errorBag);

		$this->input   = $input;
		$this->message = $this->__toString();
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		$errors = $this->validator->errors()->messages();

		$message = count($errors) . ' error' . (count($errors) === 1 ? '' : 's') . ' validating ' . get_class($this->input) . ':';

		foreach($errors as $error) {
			if (is_array($error)) {
				foreach($error as $err) {
					$message .= "\n$err";
				}
			} else {
				$message .= "\n$error";
			}
		}

		return $message;
	}
}
