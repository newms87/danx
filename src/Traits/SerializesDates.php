<?php

namespace Newms87\DanxLaravel\Traits;

use DateTimeInterface;

trait SerializesDates
{
	protected function serializeDate(DateTimeInterface $date)
	{
		return $date->format('Y-m-d H:i:s');
	}
}
