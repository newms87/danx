<?php


namespace Newms87\Danx\Resources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class ActionResource
{
    public static function make(Model $model, array $attributes): array
    {
        return $attributes + [
                'id'          => $model->getKey(),
                '__type'      => $model::class,
                '__timestamp' => request()->header('X-Timestamp') ?: LARAVEL_START,
            ];
    }

    public static function collection(Collection|array $collection, $nestedCallback = null)
    {
        $items = [];

        foreach($collection as $item) {
            $items[] = static::data($item, $nestedCallback ? $nestedCallback($item) : []);
        }

        return $items;
    }

    abstract public static function data(Model $model, array $attributes = []): array;

    public static function details(Model $model): array
    {
        return static::data($model);
    }
}
