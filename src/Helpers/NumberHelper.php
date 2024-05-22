<?php

namespace Newms87\DanxLaravel\Helpers;

class NumberHelper
{
	/**
	 * Creates an array where each element is the next step in the logarithmic algorithm. step 1 is always 0 and the
	 * last step is the given value. Each step is rounded up to the left most digit (eg: 84,739 is round up to 90,000)
	 *
	 * @param int $min    - the minimum (first element) value in the array
	 * @param int $max    - the maximum (last element) value in the array
	 * @param int $steps  - the number of steps in the array (eg: the length of the array)
	 * @param int $offset - added to each value in the array before rounded up
	 * @return array - an array of length equal to $steps
	 */
	public static function logarithmicArray($min, $max, $steps = 5, $offset = 0)
	{
		$diff = $max - $min;
		$log  = log($diff, $steps);

		$values = [$min];

		for($i = 2; $i <= $steps; $i++) {
			$values[] = $min + self::roundToLeftDigit((int)pow($i, $log) + $offset);
		}

		return $values;
	}

	/**
	 * round up to the nearest left most digit (eg: 3,543 is rounded up to 4,000)
	 *
	 * @param $value
	 * @return int
	 */
	public static function roundToLeftDigit($value)
	{
		$div = (int)str_pad('1', strlen($value), '0');

		//The maximum value, the value rounded to the nearest leftmost digit
		return (int)ceil($value / $div) * $div;
	}

	/**
	 * @param $value
	 * @return int
	 */
	public static function cleanInt($value)
	{
		// NOTE: leave "." decimals in place, so we can round
		return round((float)preg_replace('/[^0-9]./', '', $value));
	}

	/**
	 * @param $value
	 * @return float
	 */
	public static function cleanFloat($value)
	{
		return (float)preg_replace('/[^0-9.]/', '', $value);
	}
}
