<?php

namespace Newms87\Danx\Traits;

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

			// Track deletes before they happen in the database because we need to first look up the related models
			// before the association is removed
			$relatedModelClass::deleting(function (Model $model) {
				static::syncRelatedModels($model, true);
			});
		}
	}

	public static function syncRelatedModels(Model $childModel, $isDelete = false): void
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
				$query = $relatedModel->{$relationshipName}();
				// We have to exclude the current model from the count if it's being deleted since we are tracking this before the delete actually happens
				if ($isDelete) {
					$query->where($childModel->getKeyName(), '!=', $childModel->getKey());
				}
				$relatedModel->forceFill([$counterField => $query->count()])->save();
			}
		}
	}
}
