<?php

namespace Newms87\Danx\Traits;

use DateTimeInterface;

trait SerializesDates
{
	/**
	 * Format for storing dates in the database with millisecond precision.
	 * Requires timestamp(3) columns in migrations.
	 */
	public function getDateFormat(): string
	{
		return 'Y-m-d H:i:s.v';
	}

	/**
	 * Format for serializing dates to JSON with millisecond precision.
	 */
	protected function serializeDate(DateTimeInterface $date): string
	{
		return $date->format('Y-m-d H:i:s.v');
	}
}
