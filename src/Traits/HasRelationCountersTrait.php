<?php

namespace Newms87\Danx\Traits;

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

	public function updateRelationCounter($relationshipName): void
	{
		$relatedModel = $this->$relationshipName()->getRelated();
		$counterField = $this->relationCounters[$relatedModel::class][$relationshipName];

		$this->forceFill([$counterField => $this->$relationshipName()->count()])->save();
	}

	public static function syncRelatedModels(Model $relatedModel, $isDelete = false): void
	{
		// Resolve the relationship counters based on the child model's class
		$modelCounters = (new static)->relationCounters[$relatedModel::class] ?? null;

		if (!$modelCounters) {
			throw new Exception(static::class . " does not have any relation counters defined for " . $relatedModel::class);
		}

		foreach($modelCounters as $relationshipName => $counterField) {
			// First query the parent models that depend on the child that was modified
			$parentModels = static::resolveRelationCountersParentModels($relationshipName, $relatedModel);

			// Then loop through each parent and sync the count of the number of all child models with the same relationship as the given child model
			foreach($parentModels as $parentModel) {
				$query = $parentModel->{$relationshipName}();
				// We have to exclude the current model from the count if it's being deleted since we are tracking this before the delete actually happens
				if ($isDelete) {
					$query->where($relatedModel->getQualifiedKeyName(), '!=', $relatedModel->getKey());
				}

				$parentModel->forceFill([$counterField => $query->count()])->save();
			}
		}
	}

	public static function resolveRelationCountersParentModels($relationshipName, Model $relatedModel)
	{
		$relationshipMethod = (new static)->$relationshipName();

		// Handle Morph to Many relationships
		if ($relationshipMethod instanceof MorphToMany) {
			$relatedPivotKey = $relationshipMethod->getQualifiedRelatedPivotKeyName();
			$foreignId       = $relatedModel->getKey();

			return static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where($relatedPivotKey, $foreignId))->get();
		}

		// Handle Morph pivot type relationships
		if ($relatedModel instanceof MorphPivot) {
			$foreignKey = $relatedModel->getForeignKey();
			$foreignId  = $relatedModel->$foreignKey;

			if ($relationshipMethod instanceof MorphMany) {
				return static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where($foreignKey, $foreignId))->get();
			}

			return static::query()->where($foreignKey, $foreignId)->get();
		}

		// Handle all other relation
		return static::query()->whereHas($relationshipName, fn(Builder $builder) => $builder->where($relatedModel->getQualifiedKeyName(), $relatedModel->id))->get();
	}
}
