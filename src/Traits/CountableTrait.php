<?php

namespace Newms87\Danx\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait CountableTrait
{
	public static function bootCountableTrait()
	{
		static::created(function (Model $model) {
			static::syncRelatedModels($model);
		});

		static::deleted(function (Model $model) {
			static::syncRelatedModels($model);
		});
	}

	public static function syncRelatedModels(Model $model)
	{
		if (!$model->relatedCounters) {
			throw new \Exception("You must define the property \$relatedCounters = [...] in " . get_class($model) . " to use the CountableTrait.");
		}

		/* @var Model|static $model */
		foreach($model->relatedCounters as $relatedModelName => $counterField) {
			/** @var Model $relatedModel */
			$relatedModel   = new $relatedModelName;
			$relatedModelFK = $relatedModel->getForeignKey();
			$relatedModelId = $model->$relatedModelFK;
			$count          = $model->query()->where($relatedModelFK, $relatedModelId)->count();
			$relatedModel->query()->find($relatedModelId)->forceFill([$counterField => $count])->save();
		}
	}
}
