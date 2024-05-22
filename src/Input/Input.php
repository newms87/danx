<?php

namespace Newms87\DanxLaravel\Input;

use Exception;
use Newms87\DanxLaravel\Exceptions\InputValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Validation\ValidationException;

class Input extends Collection
{
	/**
	 * @return $this
	 *
	 * @throws ValidationException
	 */
	public function validate()
	{
		if ($this->getValidator()->fails()) {
			throw new InputValidationException($this);
		}

		return $this;
	}

	/**
	 * @return \Illuminate\Contracts\Validation\Validator|\Illuminate\Validation\Validator
	 */
	public function getValidator()
	{
		return Validator::make($this->all(), $this->rules(), $this->messages(), $this->attributes());
	}

	/**
	 * Define the Validator Rules here
	 *
	 * @return array
	 */
	public function rules()
	{
		return [];
	}

	/**
	 * Optionally add custom error messages here
	 *
	 * @return array
	 */
	public function messages()
	{
		return [
			'required' => ':attribute is required',
		];
	}

	/**
	 * Optionally add custom attributes here
	 *
	 * @return array
	 */
	public function attributes()
	{
		return [];
	}

	/**
	 * Fills all items based on the key of each item, overwriting any existing entries at each key.
	 * Does not remove any items from the list
	 *
	 * @param $items
	 * @return static
	 */
	public function fill($items)
	{
		foreach($items as $key => $item) {
			$this->put($key, $item);
		}

		return $this;
	}

	/**
	 * Compare this Input's values to another input of the same type to check for changes
	 *
	 * @param       $input
	 * @param array $exceptKeys
	 * @return bool true if identical, false otherwise
	 */
	public function isEqualTo($input, $exceptKeys = [])
	{
		return !$this->diffComparison($input, $exceptKeys, true);
	}

	/**
	 * Compare the stored items against a different set of items using the $keys as the indexes to compare
	 *
	 * @param array|string $keys
	 * @param              $values
	 * @return bool
	 */
	public function hasKeysEqualTo(array|string $keys, $values)
	{
		if (!is_array($keys)) {
			$keys = [$keys];
		}

		foreach($keys as $key) {
			$original = $values instanceof Input ? $values->get($key) : ($values[$key] ?? null);
			$new      = $this->get($key);

			if ($original != $new) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $input
	 * @return array
	 */
	public function diffComparison($input, $exceptKeys = [], $stopOnFirst = false)
	{
		$diff = [];

		foreach($this->all() as $key => $value) {
			if (!in_array($key, $exceptKeys)) {
				// NOTE: Loose comparison here is important! The API response and our data stored are quite different for equal values
				if (!array_key_exists($key, $input) || $input[$key] != $value) {

					// Handle array comparison to see if any of the values set in our array deviate from the equivalent value in the comparison array
					if (array_key_exists($key, $input) && is_array($value)) {
						if (array_is_numeric($value)) {
							if (!array_diff($value, $input[$key])) {
								continue;
							}
						} else {
							if (!array_diff_assoc($value, $input[$key])) {
								continue;
							}
						}
					}

					$diff[$key] = [$value, $input[$key] ?? null];

					if ($stopOnFirst) {
						return $diff;
					}
				}
			}
		}

		return $diff;
	}

	/**
	 * Override the Base Collection so we can access items in object fashion
	 *
	 * @param string $key
	 * @return HigherOrderCollectionProxy|mixed
	 *
	 * @throws Exception
	 */
	public function __get($key)
	{
		if (array_key_exists($key, $this->items)) {
			return $this->items[$key];
		}

		return parent::__get($key);
	}
}
