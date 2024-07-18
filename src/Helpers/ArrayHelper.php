<?php

namespace Newms87\Danx\Helpers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ArrayHelper
{
	/**
	 * Flattens an associative array by recursively removing nested arrays and prefixing child keys with the parent key
	 * name
	 *
	 * @param        $array
	 * @param string $prefix
	 * @return array
	 */
	public static function flatMapAssoc($array, $prefix = '')
	{
		$result = [];

		foreach($array as $key => $value) {
			if (is_array($value)) {
				$result = array_merge($result, self::flatMapAssoc($value, $prefix . $key . '.'));
			} else {
				$result[$prefix . $key] = $value;
			}
		}

		return $result;
	}

	/**
	 * @param      $array1
	 * @param      $array2
	 * @param bool $ignoreMissingKeys
	 * @return array
	 */
	public static function flattenAndDiffNestedAssoc($array1, $array2, $ignoreMissingKeys = false)
	{
		$array1 = self::flatMapAssoc($array1);
		$array2 = self::flatMapAssoc($array2);

		if ($ignoreMissingKeys) {
			$array1 = array_intersect_key($array1, $array2);
			$array2 = array_intersect_key($array2, $array1);
		}

		return array_diff_assoc($array1, $array2);
	}

	/**
	 * Group an array by a dot notation key
	 *
	 * @param              $array
	 * @param string|array $key Either dot notation key or an array of keys. ie: ['key1', 'key2', 'key3'] or
	 *                          'key1.key2.key3'
	 * @return array
	 */
	public static function groupByDot($array, $key): array
	{
		// If the array is not an array just return
		if (!is_array($array)) {
			return [];
		}

		$keys     = is_array($key) ? $key : explode('.', $key);
		$firstKey = array_shift($keys);

		if (array_key_exists($firstKey, $array)) {
			return self::groupByDot($array[$firstKey], $keys);
		}

		$result = [];
		foreach($array as $item) {
			if (!is_array($item)) {
				$result[] = $item;
			} elseif (array_key_exists($firstKey, $item)) {
				$result[$item[$firstKey]] = $item;
			} else {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Convert an array to a string using the keys as a label and the values as the content
	 * The keys will be converted to Headline case (ie: replacing underscores/dashes/dots with spaces and capitalizing
	 * each word)
	 *
	 * @param        $array
	 * @param string $separator
	 * @param string $valueSeparator
	 * @return string
	 */
	public static function toHeadlineString($array, $separator = "\n", $valueSeparator = ', ')
	{
		return implode($separator, Arr::map($array,
			fn($value, $key) => Str::headline(str_replace('.', ' ', $key)) . ": " . (is_array($value) ? implode(', ',
					$value) : $value)));
	}

	public static function recursiveUpdate(array $array1, array $array2): array
	{
		foreach($array2 as $key => $value) {
			if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
				$array1[$key] = static::recursiveUpdate($array1[$key], $value);
			} else {
				$array1[$key] = $value;
			}
		}

		return $array1;
	}

	public static function recursiveKsort(&$array): void
	{
		foreach($array as &$value) {
			if (is_array($value)) {
				static::recursiveKsort($value);
			}
		}
		ksort($array);
	}

	public static function extractNestedData($data, $includedFields): array|string|int|float|bool|null
	{
		if (!$includedFields || !$data || is_scalar($data)) {
			return $data;
		}

		$extracted = [];
		foreach($includedFields as $field) {
			$value = data_get($data, $field);

			// Field can be an array of fields to extract
			if ($value !== null) {
				ArrayHelper::setNestedData($extracted, $field, $value);
			}
		}

		return $extracted;
	}

	public static function setNestedData(&$data, $field, $value): void
	{
		$keys    = explode('.', $field);
		$current = &$data;

		foreach($keys as $i => $key) {
			if ($key === '*') {
				if (!is_array($current)) {
					$current = [];
				}

				// If this is the second-to-last key, create or update the structure
				if ($i === count($keys) - 2) {
					$lastKey = $keys[$i + 1];
					foreach($value as $index => $item) {
						if (!isset($current[$index])) {
							$current[$index] = [];
						}
						$current[$index][$lastKey] = $item;
					}

					return;
				}
			} else {
				if (!isset($current[$key])) {
					$current[$key] = [];
				}
				$current = &$current[$key];
			}
		}

		$current = $value;
	}
}
