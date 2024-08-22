<?php


namespace Newms87\Danx\Resources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class ActionResource
{
	public static string $type = '';

	public static function typedData(Model $model): array
	{
		$type = static::$type ?: basename(preg_replace("#\\\\#", "/", static::class));

		return [
			'id'          => $model->getKey(),
			'__type'      => $type,
			'__timestamp' => request()->header('X-Timestamp') ?: microtime(true),
		];
	}

	public static function make(Model $model = null, array $attributes = []): array|null
	{
		if (!$model) {
			return null;
		}

		return $attributes + static::data($model) + static::typedData($model);
	}

	public static function collection(Collection|array|null $collection, $nestedCallback = null)
	{
		if (!$collection) {
			return [];
		}

		$items = [];

		foreach($collection as $item) {
			$items[] = static::make($item, $nestedCallback ? $nestedCallback($item) : []);
		}

		return $items;
	}

	abstract public static function data(Model $model): array;

	public static function details(Model $model): array
	{
		return static::make($model);
	}

	public static function relation(Model $model, $relation): array
	{
		if (!method_exists(static::class, $relation)) {
			throw new \Exception('Relation ' . $relation . ' does not exist on ' . static::class);
		}

		return static::{$relation}($model) + static::typedData($model);
	}
}
