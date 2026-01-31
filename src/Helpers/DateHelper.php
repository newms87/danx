<?php

namespace Newms87\Danx\Helpers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Exception;

class DateHelper
{
	const
		FREQUENCY_WEEKLY = 1,
		FREQUENCY_BI_WEEKLY = 2,
		FREQUENCY_MONTHLY = 3,
		TIMER_MILLISECOND = 1000,
		TIMER_SECOND = 1,
		TIMER_MINUTE = 1 / 60;

	const DAY_OF_WEEK_MAP = [
		'sunday'    => CarbonInterface::SUNDAY,
		'monday'    => CarbonInterface::MONDAY,
		'tuesday'   => CarbonInterface::TUESDAY,
		'wednesday' => CarbonInterface::WEDNESDAY,
		'thursday'  => CarbonInterface::THURSDAY,
		'friday'    => CarbonInterface::FRIDAY,
		'saturday'  => CarbonInterface::SATURDAY,
	];

	const MONTHS = [
		1  => [
			'name'  => 'January',
			'short' => 'Jan',
		],
		2  => [
			'name'  => 'February',
			'short' => 'Feb',
		],
		3  => [
			'name'  => 'March',
			'short' => 'Mar',
		],
		4  => [
			'name'  => 'April',
			'short' => 'Apr',
		],
		5  => [
			'name'  => 'May',
			'short' => 'May',
		],
		6  => [
			'name'  => 'June',
			'short' => 'Jun',
		],
		7  => [
			'name'  => 'July',
			'short' => 'Jul',
		],
		8  => [
			'name'  => 'August',
			'short' => 'Aug',
		],
		9  => [
			'name'  => 'September',
			'short' => 'Sep',
		],
		10 => [
			'name'  => 'October',
			'short' => 'Oct',
		],
		11 => [
			'name'  => 'November',
			'short' => 'Nov',
		],
		12 => [
			'name'  => 'December',
			'short' => 'Dec',
		],
	];

	/** @var float Timestamp when timer was initiated */
	public static $timerStart = [];

	/**
	 * The difference of days between 2 days. If the 2 days are the same day, it will return 1.
	 * This is safe for computing date differences across daylight savings time.
	 *
	 * @param Carbon $startDate
	 * @param Carbon $endDate
	 * @return int
	 */
	public static function absoluteDiffInDays(Carbon $startDate, Carbon $endDate): int
	{
		return round($endDate->endOfDay()->diffInHours($startDate->startOfDay()) / 24);
	}
	/**
	 * Get string value for Week From Date
	 *
	 * @param $date
	 * @return string
	 */
	public static function getWeekStringFromDate(Carbon $date): string
	{
		$startMonth = $date->startOfWeek()->format('M');
		$endMonth   = $date->endOfWeek()->format('M');

		if ($startMonth == $endMonth) {
			return $date->startOfWeek()->format('M d') . '-' . $date->endOfWeek()->format('d') . ', ' . $date->endOfWeek()->format('Y');
		}

		return $date->startOfWeek()->format('M d') . '-' . $date->endOfWeek()->format('M d') . ', ' . $date->endOfWeek()->format('Y');
	}

	/**
	 * Helper method to build date arrays
	 *
	 * @param string $dateString
	 * @param array  $dates
	 * @param float  $pricePerDay
	 * @param Carbon $date
	 * @return array
	 */
	public static function addToDateArray(string $dateString, array $dates, float $pricePerDay, Carbon $date): array
	{
		if (array_key_exists($dateString, $dates)) {
			$dates[$dateString] += $pricePerDay;
		} else {
			$dates[$dateString] = $pricePerDay;
		}

		return $dates;
	}

	/**
	 * @param array $options
	 * @return array|Carbon[]
	 */
	public static function generateSchedule($options = [])
	{
		$options += [
			'frequency'  => self::FREQUENCY_WEEKLY,
			'daysOfWeek' => [],
			'months'     => [],
			'dayOfMonth' => false,
			'startDate'  => now(),
			'endDate'    => now()->addMonth(1),
			'exceptions' => [],
		];

		// Convert days of week to Carbon const enum
		foreach($options['daysOfWeek'] as &$dayOfWeek) {
			$dayOfWeek = self::DAY_OF_WEEK_MAP[strtolower($dayOfWeek)] ?? $dayOfWeek;
		}
		unset($dayOfWeek);

		$exceptions = [];

		foreach($options['exceptions'] as $exception) {
			$exceptions[] = new Carbon($exception);
		}

		$startDate = new Carbon($options['startDate']);
		$endDate   = new Carbon($options['endDate']);
		$currDate  = $startDate->copy();

		//The # of times this day of week has appeared this month
		$nthDayOfWeekThisMonth = [];
		$currMonth             = 0;
		$selectedDates         = [];

		while($currDate->lte($endDate)) {
			if ($currMonth !== $currDate->month) {
				$currMonth             = $currDate->month;
				$nthDayOfWeekThisMonth = [0, 0, 0, 0, 0, 0, 0];
			}

			$nthDayOfWeekThisMonth[$currDate->dayOfWeek]++;

			$isValidDayOfWeek = !$options['daysOfWeek'] || in_array($currDate->dayOfWeek, $options['daysOfWeek']);

			$isValidMonth = (!$options['months'] || in_array(
					$currDate->month,
					$options['months']
				));

			if ($isValidDayOfWeek && $isValidMonth) {
				$isValid = true;

				switch($options['frequency']) {
					case self::FREQUENCY_WEEKLY:
						break;

					case self::FREQUENCY_BI_WEEKLY:
						//Every other week, but sunday is technically the last day of the week, so we want the sunday of the previous week
						if ($currDate->dayOfWeek === CarbonInterface::SUNDAY ? $currDate->weekOfYear % 2 === 0 : $currDate->weekOfYear % 2 === 1) {
							$isValid = false;
						}
						break;

					case self::FREQUENCY_MONTHLY:
						if ($options['dayOfMonth']) {
							//Only valid if the correct day of the month
							if ($options['dayOfMonth'] !== $currDate->day) {
								$isValid = false;
							}
						} elseif ($nthDayOfWeekThisMonth[$currDate->dayOfWeek] !== 1) {
							//Only on the first Sunday, Monday, etc. of the month
							$isValid = false;
						}

						break;
				}

				// Don't add exception dates
				if ($exceptions) {
					foreach($exceptions as $exception) {
						if ($exception->isSameDay($currDate)) {
							$isValid = false;
						}
					}
				}

				if ($isValid) {
					$selectedDates[] = new Carbon($currDate);
				}
			}

			$currDate->addDays(1);
		}

		return $selectedDates;
	}

	/**
	 * Formats a date into human-readable format
	 */
	public static function formatDate(Carbon|string $date = '', $format = 'm/d/Y'): string|false
	{
		return static::formatDateTime($date, $format);
	}

	/**
	 * Formats a date and time into human-readable format
	 */
	public static function formatDateTime(Carbon|string $date = null, $format = 'm/d/Y H:i:s'): string|false
	{
		try {
			return carbon($date)->format($format);
		} catch(Exception $e) {
			return false;
		}
	}

	/**
	 * Formats a date into a database-friendly format
	 */
	public static function formatDateDB($datetime, $format = 'Y-m-d'): false|string
	{
		return static::formatDateTime($datetime, $format);
	}

	/**
	 * Formats a date and time into a database-friendly format
	 */
	public static function formatDateTimeDB($datetime, $format = 'Y-m-d H:i:s'): false|string
	{
		return static::formatDateTime($datetime, $format);
	}

	/**
	 * Format a duration in milliseconds to a human-readable string.
	 * Always shows verbose format with all units down to the smallest specified.
	 *
	 * Examples (with ms - default):
	 *   550ms, 1s 300ms, 4s 0ms, 5m 0s 200ms, 12m 30s 0ms, 4h 0m 0s 0ms
	 *
	 * Examples (without ms - rounded):
	 *   1s, 4s, 5m 0s, 12m 30s, 4h 0m 0s
	 *
	 * Examples (without seconds - rounded):
	 *   1m, 5m, 12m, 4h 0m
	 *
	 * @param int    $ms      Duration in milliseconds
	 * @param string $minUnit Smallest unit to show: 'ms', 's', or 'm'. Smaller units are rounded.
	 */
	public static function formatDuration(int $ms, string $minUnit = 'ms'): string
	{
		// Round based on minimum unit
		if ($minUnit === 's') {
			$ms = (int)round($ms / 1000) * 1000;
		} elseif ($minUnit === 'm') {
			$ms = (int)round($ms / 60000) * 60000;
		}

		$totalSeconds = (int)floor($ms / 1000);
		$milliseconds = $ms % 1000;

		$hours   = (int)floor($totalSeconds / 3600);
		$minutes = (int)floor(($totalSeconds % 3600) / 60);
		$seconds = $totalSeconds % 60;

		$parts = [];

		if ($hours > 0) {
			$parts[] = "{$hours}h";
			$parts[] = "{$minutes}m";
			$parts[] = "{$seconds}s";
			if ($minUnit === 'ms') {
				$parts[] = "{$milliseconds}ms";
			}
		} elseif ($minutes > 0) {
			$parts[] = "{$minutes}m";
			$parts[] = "{$seconds}s";
			if ($minUnit === 'ms') {
				$parts[] = "{$milliseconds}ms";
			}
		} elseif ($seconds > 0 || $minUnit !== 'ms') {
			$parts[] = "{$seconds}s";
			if ($minUnit === 'ms') {
				$parts[] = "{$milliseconds}ms";
			}
		} else {
			$parts[] = "{$milliseconds}ms";
		}

		return implode(' ', $parts);
	}

	/**
	 * Resets the timer
	 */
	public static function timerReset($name = 'default')
	{
		self::$timerStart[$name] = microtime(true);
	}

	/**
	 * @param string $name
	 * @param int    $precision
	 * @param int    $unit
	 * @param null   $end
	 * @param null   $start
	 * @return float
	 */
	public static function timer(
		$name = null,
		int $precision = 2,
		int $unit = self::TIMER_MILLISECOND
	)
	{
		if (!$name) {
			$name = 'default';
		}

		$start = self::$timerStart[$name];
		$end   = microtime(true);

		return round(($end - $start) * $unit, $precision);
	}

	/**
	 * @param     $name
	 * @param     $precision
	 * @param int $unit
	 * @return string
	 */
	public static function timerStr($name = null, $precision = 2, int $unit = self::TIMER_MILLISECOND)
	{
		static $lastTimestamp = [];
		$name             = $name ?? 'default';
		$currentTimestamp = static::timer($name, $precision, $unit);
		$timeSinceLast    = round($currentTimestamp - ($lastTimestamp[$name] ?? 0), $precision);

		// Show the current timing since the last timestamp
		$ms = $unit === self::TIMER_MILLISECOND ? (int)$timeSinceLast : (int)($timeSinceLast * 1000);
		$str = static::formatDuration($ms);
		// Show the total time since the timer was started
		$totalMs = $unit === self::TIMER_MILLISECOND ? (int)$currentTimestamp : (int)($currentTimestamp * 1000);
		$str .= ' (' . static::formatDuration($totalMs) . ')';

		$lastTimestamp[$name] = $currentTimestamp;

		return "[$name: $str]";
	}
}
