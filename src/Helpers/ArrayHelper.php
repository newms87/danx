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

	public static function crossProductExtractData(array $data, array $fields): array
	{
		$crossProduct = [];
		foreach($fields as $field) {
			$extracted = data_get($data, $field);

			if (!is_array($extracted) || !array_is_numeric($extracted)) {
				$extracted = [$extracted];
			}

			$newCrossProduct = [];
			foreach($extracted as $item) {
				if (!$crossProduct) {
					$newCrossProduct[] = [$field => $item];
				} else {
					foreach($crossProduct as $crossProductItem) {
						$crossProductItem[$field] = $item;
						$newCrossProduct[]        = $crossProductItem;
					}
				}
			}

			$crossProduct = $newCrossProduct;
		}

		return $crossProduct;
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

	/**
	 * Recursively filters nested data by a field and value.
	 *  - For top level filters, will return null if the field does not exist, or the fields value does not match
	 *    provided value.
	 *  - For nested filters, will always return the higher-level data structure, and will filter out the nested
	 *    data
	 *
	 *  For example:
	 *  Given the data:
	 *  [
	 *    'name' => 'Dan Newman',
	 *    'addresses' => [
	 *       ['zip' => '12345', 'type' => 'primary'],
	 *       ['zip' => '80033', 'type' => 'shipping'],
	 *       ['zip' => '800349', 'type' => 'billing'],
	 *    ],
	 *  ]
	 *
	 * - field = 'addresses.*.zip' and value = '800349'
	 *   return:
	 * [
	 *   'addresses' => [
	 *     ['zip' => '800349', 'type' => 'billing'],
	 *   ],
	 * ]
	 *
	 * - field = 'name' and value = 'Bill'
	 *   return: null
	 *
	 * - field = 'name' and value = 'Dan Newman'
	 *  return: [...] (the original data)
	 */
	public static function filterNestedData($data, $field, $value): ?array
	{
		$keys    = $field ? explode('.', $field) : [];
		$current = &$data;

		foreach($keys as $i => $key) {
			// If the next key is a recursion into a nested array, recursively filter the data
			if ($key === '*') {
				// If the key is * and current data is not an array, return null
				if (!is_array($current)) {
					return null;
				}

				$childKey = implode('.', array_slice($keys, $i + 1));

				$current = array_values(array_filter(array_map(fn($currentItem) => static::filterNestedData($currentItem, $childKey, $value), $current), fn($currentItem) => $currentItem !== null));

				return $current ? $data : null;
			} else {
				if (!isset($current[$key])) {
					return null;
				}
				$current = &$current[$key];
			}
		}

		// Handle special cases for current when it is an array
		if (is_array($current)) {
			if (is_array($value)) {
				// If the value is non-associative, filter out any items that do not match the value object structure
				if (array_is_numeric($current)) {
					$current = array_values(array_filter($current, fn($currentItem) => static::filterNestedData($currentItem, '', $value) !== null));

					return $current ? $data : null;
				}

				// If the value is associative, make a hash of the value and current data and compare
				ArrayHelper::recursiveKsort($value);
				ArrayHelper::recursiveKsort($current);
				$valueHash   = md5(json_encode($value));
				$currentHash = md5(json_encode($current));
				if ($valueHash !== $currentHash) {
					return null;
				}
			} else {
				// If the value is a scalar, filter out any items that do not match the value
				$current = array_values(array_filter($current, fn($item) => $item === $value));
				// If none of the array items match the value, all the data is filtered out
				if (!$current) {
					return null;
				}
			}
		} elseif ($current !== $value) {
			// If current is a scalar, and it does not match the value, return null
			return null;
		}

		// if the data has not been filtered out, return the original data
		return $data;
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
