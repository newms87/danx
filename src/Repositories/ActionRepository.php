<?php

namespace Newms87\DanxLaravel\Repositories;

use Exception;
use Newms87\DanxLaravel\Exceptions\ValidationError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class ActionRepository
{
	public static string $model;

	/**
	 * Returns an empty model instance
	 * @return Model
	 * @throws Exception
	 */
	public function model(): Model
	{
		if (!isset(static::$model)) {
			throw new Exception('$model static property must be set on ' . static::class);
		}

		return new static::$model;
	}

	/**
	 * Returns an instantiated model matching the ID
	 *
	 * @param $id
	 * @return Model|null
	 * @throws Exception
	 */
	public function instance($id): ?Model
	{
		return $this->model()->find($id);
	}

	public function query(): Builder
	{
		return $this->model()->query();
	}

	/**
	 * The query that will return the list of items based on the applied filter
	 * NOTE: you should use $this->query()->with(['relationship']) method to eager load relationships for better
	 * performance
	 *
	 * @return Builder
	 */
	public function listQuery(): Builder
	{
		return $this->query();
	}

	/**
	 * Returns a summary of the item list based on the applied filter
	 *
	 * @param array $filter
	 * @return array|object
	 */
	public function summary(array $filter = []): array|object
	{
		return $this->query()->select([
			DB::raw('COUNT(*) as count'),
		])
			->filter($filter)
			->first() ?? [];
	}

	/**
	 * The dynamic and / or static list of options for the filterable fields for the model table
	 *
	 * @param array|null $filter
	 * @return array
	 */
	public function fieldOptions(?array $filter = []): array
	{
		return [];
	}

	/**
	 * Applies the action to the model
	 *
	 * @param string     $action The name of the action to perform
	 * @param Model|null $model  The model instance to apply the action to
	 * @param array      $data   The data to apply to the model
	 * @return mixed|bool|null
	 * @throws ValidationError
	 */
	public function applyAction(string $action, $model = null, ?array $data = null)
	{
		// Handle the action
		return match ($action) {
			'create' => $this->model()->fill($data)->save(),
			'update' => $model->update($data),
			'delete' => $model->delete(),
			default => throw new ValidationError("Invalid action: " . $action)
		};
	}

	/**
	 * @param $filter
	 * @param $action
	 * @param $data
	 * @return array
	 */
	public function batchAction($filter, $action, $data = [])
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
	 * @param array $filter
	 * @return array
	 */
	public function export(array $filter = [])
	{
		return $this->query()
			->filter($filter)
			->get()
			->toArray();
	}
}
