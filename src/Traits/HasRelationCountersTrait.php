<?php

namespace Newms87\Danx\Traits;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait HasRelationCountersTrait
{
	public static function bootHasRelationCountersTrait(): void
	{
		$counters = (new static)->relationCounters;

		if (!$counters) {
			throw new Exception("You must define the property \$relationCounters = [...] in " . static::class . " to use the HasRelationCountersTrait.");
		}

		/** @var Model $relatedModelClass */
		foreach(array_keys($counters) as $relatedModelClass) {
			$relatedModelClass::created(function (Model $model) {
				static::syncRelatedModels($model);
			});

			$relatedModelClass::deleted(function (Model $model) {
				static::syncRelatedModels($model);
			});
		}
	}

	public static function syncRelatedModels(Model $model): void
	{
		$modelCounters = (new static)->relationCounters[$model::class] ?? null;

		if (!$modelCounters) {
			throw new Exception(static::class . " does not have any relation counters defined for " . $model::class);
		}

		foreach($modelCounters as $relationshipName => $counterField) {
			$relatedModels = static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where('id', $model->id))->get();
			foreach($relatedModels as $relatedModel) {
				$relatedModel->forceFill([$counterField => $relatedModel->{$relationshipName}->count()])->save();
			}
		}
	}
}
