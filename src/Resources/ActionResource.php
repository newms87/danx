<?php


namespace Newms87\Danx\Resources;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class ActionResource
{
	public static string $type = '';

	public static function typedData(Model $model): array
	{
		$type = static::$type ?: basename(preg_replace("#\\\\#", "/", static::class));

		return [
			'id'           => $model->getKey(),
			'__type'       => $type,
			'__timestamp'  => $model->updated_at ?: microtime(true),
			'__deleted_at' => $model->deleted_at,
		];
	}

	public static function make(Model $model = null, array $includeFields = []): array|null
	{
		if (!method_exists(static::class, 'data')) {
			throw new Exception('Resource ' . static::class . ' must implement public static function data($model, $includeFields = []) { ... }');
		}

		if (!$model) {
			return null;
		}

		/** @noinspection PhpParamsInspection */
		$data = static::data($model, $includeFields);

		// Validate the includeFields
		foreach($includeFields as $fieldName => $field) {
			if ($fieldName !== '*' && !isset($data[$fieldName])) {
				throw new Exception('Field "' . $fieldName . '" is not a valid field for ' . static::class);
			}
		}

		$responseData = [];

		foreach($data as $fieldName => $datum) {
			// If the * special field is set, the field is automatically included
			// If the field is explicitly set, either include or exclude based on the value
			$includedField = $includeFields[$fieldName] ?? $includeFields['*'] ?? null;

			// If the field is not included, skip it
			if ($includedField === false) {
				continue;
			}

			// If the field is a callback, call it ONLY if it is explicitly included (do this recursively so child fields as well)
			if (!is_scalar($datum) && is_callable($datum)) {
				if ($includedField) {
					$responseData[$fieldName] = $datum(is_array($includedField) ? $includedField : []);
				}
			} else {
				$responseData[$fieldName] = $datum;
			}
		}

		return $responseData + static::typedData($model);
	}

	public static function collection(Collection|array|null $collection, array $includeFields = [])
	{
		if (!$collection) {
			return [];
		}

		$items = [];

		foreach($collection as $item) {
			$items[] = static::make($item, $includeFields);
		}

		return $items;
	}

	/**
	 * Return the data for a model including all top-level fields
	 *
	 * NOTE: You should override this method if you need more deeply nested fields by default for the details view.
	 *
	 * Examples for deeply nesting:
	 *  a) ['*' => ['*' => ["*" => true]]]
	 *  b) ['*' => ['prop' => ['name' => true]]]
	 */
	public static function details(Model $model, ?array $includeFields = null): array
	{
		return static::make($model, $includeFields ?? ['*' => true]);
	}
}
