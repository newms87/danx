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
	 * Maps an array using a callback, and recursively maps any nested arrays
	 */
	public static function recursiveMap(array $array, callable $callback): array
	{
		$result = [];
		foreach($array as $key => $value) {
			if (is_array($value)) {
				$result[$key] = self::recursiveMap($value, $callback);
			} else {
				$result[$key] = $callback($value, $key);
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

	/**
	 * TODO: Need to think about this more....
	 *
	 * This should recursively merge objects where they are the same, but if the objects differ, then they should be
	 * placed in arrays of objects maybe? Should there be a more intelligent way to discover if an object should be
	 * merged or concatenated?
	 */
	public static function WIP__mergeArraysRecursively($a1, $a2)
	{
		foreach($a2 as $key => $value2) {
			$value1 = $a1[$key] ?? null;

			// Base case, if array 1 doesn't have the key, just set it to array 2
			if ($value1 === null) {
				$a1[$key] = $value2;
				continue;
			}

			if ($value2 === null) {
				// If array 2 has a null value, we don't want to overwrite the value in array 1
				continue;
			}

			if (is_array($value1)) {
				if (is_associative_array($value1)) {
					if (is_associative_array($value2)) {
						// Both are associative arrays, so treat them like objects and merge the objects into a list
						$a1[$key] = [$value1, $value2];
						continue;
					}

					// Type mismatch where array 1 is associative and array 2 is not,
					// so treat value1 like an object and value 2 like a list of objects and merge the objects into a flat list
					$a1[$key] = array_merge([$value1], $value2);
					continue;
				}

				if (is_associative_array($value2)) {
					// Type mismatch where array 1 is not associative and array 2 is,
					// so treat value1 like a list of objects and value 2 like an object and merge the objects into a flat list
					$a1[$key] = array_merge($value1, [$value2]);
					continue;
				}

				// Both are non-associative arrays, so treat them like lists of objects and merge the objects into a flat list
				$a1[$key] = array_merge($value1, $value2);
				continue;
			}

			if (is_array($value2)) {
				// Type mismatch where array 1 is not an array and array 2 is,
				// so treat value1 like an object and value 2 like a list of objects and merge the objects into a flat list
				$a1[$key] = array_merge([$value1], $value2);
			} else {
				// Both values are scalar values, so merge them into a list
				$a1[$key] = [$value1, $value2];
			}
		}

		return $a1;
	}

	/**
	 * Merges 1 or more arrays recursively using array_merge_recursive(), and additionally ensuring each array entry of
	 * scalar values are unique
	 */
	public static function mergeArraysRecursivelyUnique(array ...$arrays): array
	{
		$merged = array_merge_recursive(...$arrays);

		return static::recursivelyUnique($merged);
	}

	/**
	 * Recursively removes duplicate values from an array
	 */
	public static function recursivelyUnique(array $array): mixed
	{
		foreach($array as $key => $value) {
			if (is_array($value)) {
				$array[$key] = self::recursivelyUnique($value);
			}
		}

		if (!empty($array) && !is_associative_array($array) && (is_scalar(reset($array)) || reset($array) === null)) {
			// Only unique entries
			$array = array_unique($array, SORT_REGULAR);

			// If there are non-null entries, clear out the null entries as these mean nothing
			$array = array_filter($array, fn($i) => $i !== null);

			// If the array only contains 1 item, we can reduce it into a scalar value
			if (count($array) === 1) {
				return reset($array);
			}

			// These are non-associative arrays, so make sure we're properly indexed
			$array = array_values($array);
		}

		return $array;
	}

	public static function crossProduct(array $groups): array
	{
		$result = [[]];
		foreach($groups as $group) {
			$newResult = [];
			foreach($result as $resultItem) {
				foreach($group as $groupItem) {
					$newResult[] = array_merge($resultItem, [$groupItem]);
				}
			}
			$result = $newResult;
		}

		return $result;
	}
}
