<?php

namespace Newms87\Danx\Repositories;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Newms87\Danx\Exceptions\ValidationError;

abstract class ActionRepository
{
	public static string $model;

	/**
	 * Returns an empty model instance
	 */
	public function model(): Model
	{
		if (!isset(static::$model)) {
			throw new Exception('$model static property must be set on ' . static::class);
		}

		return new static::$model;
	}

	/**
	 * Returns an instantiated model matching the ID.
	 * Supports withTrashed query parameter to include soft-deleted records.
	 */
	public function instance($id): ?Model
	{
		$query = $this->model()->query();

		if (request()->boolean('withTrashed') && method_exists($this->model(), 'trashed')) {
			$query->withTrashed();
		}

		return $query->find($id);
	}

	/**
	 * construct a query for the model the repo is connected to
	 */
	public function query(): Builder
	{
		return $this->model()->query();
	}

	/**
	 * The query that will return the list of items based on the applied filter
	 * NOTE: you should use $this->query()->with(['relationship']) method to eager load relationships for better
	 * performance
	 */
	public function listQuery(): Builder
	{
		return $this->query();
	}

	/**
	 * Returns a query for the summary of the item list based on the applied filter
	 */
	public function summaryQuery(array $filter = []): Builder|QueryBuilder
	{
		return $this->query()->select([
			DB::raw('COUNT(*) as count'),
		])
			->filter($filter);
	}

	/**
	 * Returns a summary of the item list based on the applied filter
	 */
	public function summary(array $filter = []): array|object
	{
		return $this->summaryQuery($filter)->first() ?? [];
	}

	/**
	 * The dynamic and / or static list of options for the filterable fields for the model table
	 */
	public function fieldOptions(?array $filter = []): array
	{
		return [];
	}

	/**
	 * Applies the action to the model
	 *
	 * @param string           $action The name of the action to perform
	 * @param null|Model|array $model  The model instance to apply the action to
	 * @param array|null       $data   The data to apply to the model
	 */
	public function applyAction(string $action, Model|null|array $model = null, ?array $data = null)
	{
		// Handle the action
		return match ($action) {
			'create' => $this->model()->fill($data)->save(),
			'update' => $model->update($data),
			'copy' => $model->replicate()->save(),
			'delete' => $model->delete(),
			default => throw new ValidationError("Invalid action: " . $action)
		};
	}

	/**
	 * Perform an action on a list of items defined by the filter
	 */
	public function batchAction($filter, $action, $data = []): array
	{
		$items = $this->query()->filter($filter)->get();

		$errors = [];

		foreach($items as $item) {
			try {
				$this->applyAction($action, $item, $data);
			} catch(Exception $e) {
				$errors[] = [
					'id'      => $item->id,
					'message' => ($item->ref ?? $item->id) . ": " . $e->getMessage(),
				];
			}
		}

		return $errors;
	}

	/**
	 * Export the list of items based on the applied filter
	 */
	public function export(?array $filter = []): array
	{
		return $this->query()
			->filter($filter)
			->get()
			->toArray();
	}
}
