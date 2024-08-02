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
	 * Merges two arrays recursively, with the values of the second array taking precedence over the first
	 */
	public static function mergeArraysRecursively($arr1, $arr2)
	{
		foreach($arr2 as $key => $value) {
			if (is_array($value) && isset($arr1[$key]) && is_array($arr1[$key])) {
				$arr1[$key] = self::mergeArraysRecursively($arr1[$key], $value);
			} else {
				$arr1[$key] = $value;
			}
		}

		return $arr1;
	}

	/**
	 * Cross Product Extract Data takes an array of data and a list of fields to extract from the data. It then returns
	 * an array of arrays where each array is a cross product of the extracted fields.
	 */
	public static function crossProductExtractData(array $data, array $fields): array
	{
		$crossProduct = [];
		foreach($fields as $field) {
			$extracted = data_get($data, $field);

			if (!is_array($extracted) || is_associative_array($extracted)) {
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

	/**
	 * Extracts nested data from a data structure based on a list of fields. The fields can be nested using dot
	 * notation, and can include wildcards to extract all fields from an array.
	 *
	 * For example:
	 * Given the data:
	 * name: 'Dan Newman',
	 * dob: '01/01/1990',
	 * addresses:
	 *  - zip: '12345',
	 *    type: 'primary'
	 *  - zip: '80033',
	 *    type: 'shipping'
	 *
	 * $includedFields = ['name', 'addresses.*.zip']
	 * return:
	 *
	 * name: 'Dan Newman'
	 * addresses:
	 *  - zip: '12345'
	 *  - zip: '80033'
	 *
	 */
	public static function extractNestedData($data, $includedFields): array|string|int|float|bool|null
	{
		// If the data is a scalar, and a field is provided, return null since the field does not exist
		if (is_scalar($data)) {
			return $includedFields ? null : $data;
		}

		if (!$includedFields || !$data) {
			return $data;
		}

		$extracted      = [];
		$allFieldsExist = true;

		foreach($includedFields as $field) {
			$parts       = explode('.', $field);
			$current     = &$extracted;
			$source      = $data;
			$fieldExists = true;

			foreach($parts as $i => $part) {
				if ($part === '*') {
					if (!is_array($source)) {
						$fieldExists = false;
						break;
					}
					foreach($source as $key => $value) {
						if (!isset($current[$key])) {
							$current[$key] = [];
						}
						$nextPart = $parts[$i + 1] ?? null;
						if ($nextPart) {
							$subFields    = [implode('.', array_slice($parts, $i + 1))];
							$subExtracted = self::extractNestedData($value, $subFields);
							if ($subExtracted === null) {
								$fieldExists = false;
								break 2;
							}
							$current[$key] = self::mergeArraysRecursively($current[$key], $subExtracted);
						} else {
							$current[$key] = $value;
						}
					}
					break;
				} elseif (isset($source[$part])) {
					if ($i === count($parts) - 1) {
						$current[$part] = $source[$part];
					} else {
						if (!isset($current[$part])) {
							$current[$part] = [];
						}
						$current = &$current[$part];
						$source  = $source[$part];
					}
				} else {
					$fieldExists = false;
					break;
				}
			}

			if (!$fieldExists) {
				$allFieldsExist = false;
				break;
			}
		}

		if (!$allFieldsExist) {
			return null;
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
	 *  name: Dan Newman
	 *  addresses:
	 *   - zip: '12345'
	 *     type: primary
	 *   - zip: '80033'
	 *     type: shipping
	 *   - zip: '800349'
	 *     type: billing
	 *
	 * $field = 'addresses.*.zip'
	 * $value = '800349'
	 *
	 * return:
	 * name: Dan Newman
	 * addresses:
	 * - zip: '800349'
	 *   type: billing
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
				if (!is_associative_array($current)) {
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

	/**
	 * Sets nested data in a data structure based on a field. The field can be nested using dot notation, and can
	 * include wildcards to set all fields in an array.
	 */
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

	public static function getNestedFieldList($object, $prefix = ''): array
	{
		if (empty($object)) {
			return [];
		}

		$fields = collect();

		foreach((array)$object as $fieldName => $fieldValue) {
			$fullFieldName = $prefix ? "{$prefix}.{$fieldName}" : $fieldName;
			$fields->push($fullFieldName);

			if (is_array($fieldValue) || is_object($fieldValue)) {
				if (is_associative_array($fieldValue)) {
					$nestedFields = self::getNestedFieldList($fieldValue, $fullFieldName);
					$fields       = $fields->merge($nestedFields);
				} else {
					foreach($fieldValue as $item) {
						if (is_array($item) || is_object($item)) {
							$nestedFields = self::getNestedFieldList($item, "{$fullFieldName}.*");
							$fields       = $fields->merge($nestedFields);
						}
					}
				}
			}
		}

		return $fields->unique()->values()->all();
	}
}
