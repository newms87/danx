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
			'__timestamp'  => request()->header('X-Timestamp') ?: microtime(true),
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
		$data = static::data($model, $includeFields) + static::typedData($model);

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

		return $responseData;
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
	public static function details(Model $model): array
	{
		return static::make($model, ['*' => true]);
	}

	public static function relation(Model $model, $relation): array
	{
		if (!method_exists(static::class, $relation)) {
			throw new Exception('Relation ' . $relation . ' does not exist on ' . static::class);
		}

		return static::{$relation}($model) + static::typedData($model);
	}
}
