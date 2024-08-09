<?php

namespace Newms87\Danx\Traits;

use App\Models\Workflow\WorkflowRun;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

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

	public static function syncRelatedModels(Model $childModel): void
	{
		$modelCounters = (new static)->relationCounters[$childModel::class] ?? null;

		if (!$modelCounters) {
			throw new Exception(static::class . " does not have any relation counters defined for " . $childModel::class);
		}

		foreach($modelCounters as $relationshipName => $counterField) {
			if ($childModel instanceof MorphPivot) {
				$foreignKey    = $childModel->getForeignKey();
				$foreignId     = $childModel->$foreignKey;
				$relatedModels = static::query()->where('id', $foreignId)->get();
			} else {
				$relatedModels = static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where($childModel->getQualifiedKeyName(), $childModel->id))->get();
			}
			foreach($relatedModels as $relatedModel) {
				$relatedModel->forceFill([$counterField => $relatedModel->{$relationshipName}->count()])->save();
			}
		}
	}
}
