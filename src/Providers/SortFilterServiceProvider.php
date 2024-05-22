<?php

namespace Newms87\DanxLaravel\Providers;

use Newms87\DanxLaravel\Eloquent\CustomQueryBuilder;
use Newms87\DanxLaravel\Eloquent\FilterBuilder;
use Newms87\DanxLaravel\Eloquent\SortBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class SortFilterServiceProvider extends ServiceProvider
{
	/**
	 * Registers the whereFilter Builder macro, which will take an array of filters, and apply them intelligently as
	 * where clauses in a query
	 */
	public function register()
	{
		$filterFn = function ($filter, $op = '=', $columns = []) {
			/* @var Builder $query */
			$query = $this;

			if ($filter) {
				//XXX: This is hack that happens to work with the Lighthouse GraphQL plugin. It will pass in the parameters as:
				// $query->filter('filter', '=', ?) where ? is whatever value set in the request params (eg: /getList?filter=[...])
				if ($filter === 'filter') {
					$filter = $columns;
				}

				// Make sure this is all associative arrays
				$filter = json_decode(json_encode($filter), true);
				(new FilterBuilder)->applyFilter($query, $filter);
			}

			return $query;
		};

		$filterExceptFn = function ($filter, $except = []) use ($filterFn) {
			/** @var Builder $query */
			$query  = $this;
			$filter = (array_diff_key(json_decode(json_encode($filter ?: []), true), array_combine($except, $except)));
			(new FilterBuilder)->applyFilter($query, $filter);

			return $this;
		};

		$sortFn = function ($sort, $op = '=', $columns = []) {
			/* @var Builder $query */
			$query = $this;

			if ($sort) {
				//XXX: This is hack that happens to work with the Lighthouse GraphQL plugin. It will pass in the parameters as:
				// $query->sort('sort', '=', ?) where ? is whatever value set in the request params (eg: /getList?sort=[...])
				if ($sort === 'sort') {
					$sort = $columns;
				}

				$sortBuilder = new SortBuilder($query, $sort);

				$sortBuilder->build();
			}

			return $query;
		};

		$this->registerJoinRelation();
		$this->registerClearGroupBy();
		$this->registerDateRangeFilter();

		Builder::macro('sort', $sortFn);
		Builder::macro('filter', $filterFn);
		Builder::macro('filterExcept', $filterExceptFn);
		QueryBuilder::macro('sort', $sortFn);
		QueryBuilder::macro('filter', $filterFn);
		QueryBuilder::macro('filterExcept', $filterExceptFn);
	}

	/**
	 * Registers the joinRelation macro that will allow building a join clause
	 * via the relationship of the Eloquent model
	 */
	private function registerJoinRelation()
	{
		Builder::macro('qualifiedSelect', function ($column, $as = null, $aggregate = null) {
			/* @var Builder $query */
			$query = $this;

			$builder = new CustomQueryBuilder($query);
			$builder->select($column, $as, $aggregate);

			return $query;
		});

		Builder::macro('joinRelation', function ($relation) {
			/* @var Builder $query */
			$query = $this;

			$builder = new CustomQueryBuilder($query);
			$builder->joinRelation($relation);

			return $query;
		});

		Builder::macro('joinRelationColumn', function ($column) {
			/* @var Builder $query */
			$query = $this;

			$builder = new CustomQueryBuilder($query);
			$builder->joinRelationColumn($column);

			return $query;
		});
	}

	/**
	 * Registers the clearGroupBy macro that will allow removing the Group By clause
	 * via the relationship of the Eloquent model
	 */
	private function registerClearGroupBy()
	{
		Builder::macro('clearGroupBy', function () {
			/* @var Builder $query */
			$query = $this;

			$builder = new CustomQueryBuilder($query);
			$builder->clearGroupBy();

			return $query;
		});
	}

	/**
	 * Filter by a start and end date range
	 */
	private function registerDateRangeFilter()
	{
		$filterDateRange = function ($startKey, $endKey, $dates, $nullableDates = true) {
			/* @var Builder $query */
			$query = $this;

			$builder = (new CustomQueryBuilder($query));

			$startKey = $builder->resolveColumnRelationshipAlias($startKey);
			$endKey   = $builder->resolveColumnRelationshipAlias($endKey);

			$startKeyRaw = $nullableDates ? DB::raw("IFNULL($startKey, $endKey)") : $startKey;
			$endKeyRaw   = $nullableDates ? DB::raw("IFNULL($endKey, $startKey)") : $endKey;

			if (!empty($dates['='])) {
				if (is_array($dates['=']) && count($dates['=']) > 1) {
					return $query->where(function (Builder $builder) use ($dates, $startKeyRaw, $endKeyRaw) {
						foreach($dates['='] as $date) {
							$builder->orWhere(function ($dateQuery) use ($date, $startKeyRaw, $endKeyRaw) {
								return $dateQuery->where($startKeyRaw, '<=', $date)
									->where($endKeyRaw, '>=', $date);
							});
						}
					});
				} else {
					return $query->where($startKeyRaw, '<=', $dates['='])
						->where($endKeyRaw, '>=', $dates['=']);
				}
			} else {
				$startDate = $dates['>='] ?? $dates['from'] ?? $dates[0] ?? '0001-01-01';
				$endDate   = $dates['<='] ?? $dates['to'] ?? $dates[1] ?? '9999-01-01';

				return $query->where($startKeyRaw, '<=', $endDate)
					->where($endKeyRaw, '>=', $startDate);
			}
		};

		Builder::macro('filterDateRange', $filterDateRange);
	}
}
