<?php

namespace Newms87\Danx\Traits;

use App\Models\Workflow\WorkflowRun;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait HasRelationCountersTrait
{
	public static function registerRelationshipCounters(): void
	{
		$model    = (new static);
		$counters = $model->relationCounters;

		if (!$counters) {
			throw new Exception("You must define the property \$relationCounters = [...] in " . static::class . " to use the HasRelationCountersTrait.");
		}

		/** @var Model $relatedModelClass */
		foreach($counters as $relatedModelClass => $relatedCounter) {
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
			$relatedModels = static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->withTrashed()->where($model->getQualifiedKeyName(), $model->id))->get();
			foreach($relatedModels as $relatedModel) {
				$relatedModel->forceFill([$counterField => $relatedModel->{$relationshipName}->count()])->save();
			}
		}
	}
}
