<?php


namespace Newms87\Danx\Resources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class ActionResource
{
	public static function make(Model $model = null, array $attributes = []): array|null
	{
		if (!$model) {
			return null;
		}

		return $attributes + static::data($model) + [
				'id'          => $model->getKey(),
				'__type'      => $model::class,
				'__timestamp' => request()->header('X-Timestamp') ?: LARAVEL_START,
			];
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
}
