<?php

namespace Newms87\Danx\Traits;

use App\Models\Workflow\Artifactable;
use App\Models\Workflow\WorkflowRun;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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
		// Resolve the relationship counters based on the child model's class
		$modelCounters = (new static)->relationCounters[$childModel::class] ?? null;

		if (!$modelCounters) {
			throw new Exception(static::class . " does not have any relation counters defined for " . $childModel::class);
		}

		foreach($modelCounters as $relationshipName => $counterField) {
			// First query the parent models that depend on the child that was modified
			$parentModels = static::resolveRelationCountersParentModels($relationshipName, $childModel);

			// Then loop through each parent and sync the count of the number of all child models with the same relationship as the given child model
			foreach($parentModels as $parentModel) {
				$query = $parentModel->{$relationshipName}();
				// We have to exclude the current model from the count if it's being deleted since we are tracking this before the delete actually happens
				if ($isDelete) {
					$query->where($childModel->getQualifiedKeyName(), '!=', $childModel->getKey());
				}

        		$parentModel->forceFill([$counterField => $query->count()])->save();
			}
		}
	}

	public static function resolveRelationCountersParentModels($relationshipName, Model $childModel)
	{
		$relationshipMethod = (new static)->$relationshipName();

		if ($relationshipMethod instanceof MorphToMany) {
			throw new Exception("Not yet implemented relationship MorphToMany for " . static::class);
		}

        $foreignKey = $childModel->getForeignKey();
        $foreignId  = $childModel->$foreignKey;

        if ($childModel instanceof MorphPivot) {
            if ($relationshipMethod instanceof MorphMany) {
                return static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where($foreignKey, $foreignId))->get();
            }
            return static::query()->where($foreignKey, $foreignId)->get();
        }

        return static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where($childModel->getQualifiedKeyName(), $childModel->id))->get();
	}
}
