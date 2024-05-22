<?php

namespace Newms87\DanxLaravel\Contracts;

interface ComputedStatusContract
{
	/**
	 * Computes the status of the model based on the state
	 *
	 * @return static
	 */
	public function computeStatus(): static;
}
