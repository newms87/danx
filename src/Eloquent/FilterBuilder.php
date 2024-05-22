<?php

namespace Newms87\Danx\Eloquent;

use Exception;
use Illuminate\Database\Eloquent\Builder;

class FilterBuilder
{
	const
		GROUP_AND = 'and',
		GROUP_OR = 'or';

	protected $filter;

	/**
	 * Builds the WHERE conditions for the SQL query based on the $filter
	 *
	 * @param Builder|\Illuminate\Database\Query\Builder $query
	 * @param array                                      $filter
	 *
	 * @throws Exception
	 */
	public function applyFilter($query, $filter)
	{
		if ($filter) {
			$builder = new CustomQueryBuilder($query);

			// As an optimization, anything we can filter outside the exists statement (ie: single cardinality) will help filter
			// the root table record set as well as utilize indexes, giving a huge boost to performance
			[$singleCardinalityFilter, $multipleCardinalityFilter] = $this->splitMultipleCardinalityRelationships(
				$builder,
				$filter
			);

			// Apply multiple cardinality filters inside a WHERE EXISTS statement so we do not affect the # of rows being returned from the record set
			if ($multipleCardinalityFilter) {
				$model = $query->getModel();

				$alias = $builder->getRootRelationName() . '__sub';

				$existsQuery = $model->newQuery()
					->from($model->getTable() . ' as ' . $alias)
					->select('*')
					->whereColumn($alias . '.' . $model->getKeyName(),
						$builder->getRootRelationName() . '.' . $model->getKeyName());

				foreach($multipleCardinalityFilter as $key => $value) {
					if ($key) {
						$this->addFilterClause($existsQuery, $key, $value);
					}
				}

				// No longer need group by for exists query
				(new CustomQueryBuilder($existsQuery))->clearGroupBy();

				$query->addWhereExistsQuery($existsQuery->getQuery());
			}

			// Apply Single Cardinality filters separately for a huge performance boost
			if ($singleCardinalityFilter) {
				foreach($singleCardinalityFilter as $key => $value) {
					if ($key) {
						$this->addFilterClause($query, $key, $value);
					}
				}
			}
		}
	}

	/**
	 * Checks if any items in the filter have a multiple cardinality relationship
	 * so we know if we can safely join tables in the relationship without affecting the # of duplicated records
	 *
	 * @param CustomQueryBuilder $builder
	 * @param                    $filters
	 * @return array[]
	 */
	public function splitMultipleCardinalityRelationships(CustomQueryBuilder $builder, $filters)
	{
		$singleCardinalityFilters   = [];
		$multipleCardinalityFilters = [];

		foreach($filters as $key => $value) {
			if ($key === 'and' || $key === 'or') {
				[$single, $multiple] = $this->splitMultipleCardinalityRelationships($builder, $value);

				if ($multiple) {
					$multipleCardinalityFilters[$key] = $value;
				} else {
					$singleCardinalityFilters[$key] = $value;
				}
			} else {
				// If this is a multiple cardinality key, then group this into a multiple cardinality filter
				// (which will be handled in a WHERE EXISTS clause)
				if ($builder->resolveCardinality($key) === CustomQueryBuilder::CARDINALITY_MULTIPLE) {
					$multipleCardinalityFilters[$key] = $value;
				} else {
					$singleCardinalityFilters[$key] = $value;
				}
			}
		}

		return [$singleCardinalityFilters, $multipleCardinalityFilters];
	}

	/**
	 * Adds the filter clause to the given query, recursing through nested filters
	 *
	 * @param Builder $rootQuery
	 * @param         $key
	 * @param         $value
	 * @param         $grouping
	 * @param null    $groupQuery
	 *
	 * @throws Exception
	 */
	public function addFilterClause($rootQuery, $key, $value, $grouping = self::GROUP_AND, $groupQuery = null)
	{
		if (($key === 'and' || $key === 'or') && array_is_numeric(($value))) {
			throw new Exception("$key operator cannot operate on numeric indices");
		}

		if (!$groupQuery) {
			$groupQuery = $rootQuery;
		}

		$builder = new CustomQueryBuilder($rootQuery);

		// Make sure the table exists on the root query
		$columnException = null;
		$columnKey       = null;

		try {
			$columnKey = $builder->addJoinTables($key, $value);
		} catch(Exception $exception) {
			$columnException = $exception;
		}

		$whereFn = $grouping === self::GROUP_AND ? 'where' : 'orWhere';

		if ($columnKey) {
			//If the value is null then ignore it (to compare null values, use the 'null' filter type operator)
			if ($value !== null) {
				if (!is_array($value)) {
					$groupQuery->{$whereFn}($columnKey, $value);
				} elseif (array_is_numeric($value)) {
					$this->buildWhereIn($groupQuery, $columnKey, $value, $whereFn);
				} else {
					//If the indexes are not numeric, they are operators for different types of filters
					foreach($value as $operator => $childValue) {
						$this->buildFilterType($groupQuery, $operator, $whereFn, $columnKey, $childValue);
					}
				}
			}
		} elseif ($columnKey === null) {

			// NOTE: if $columnKey === false, that means it is a Model scope, and we can ignore it

			// If the column key is not set, that means the key is for a generic grouping
			if (is_array($value)) {
				// If the Column Key is unknown, then this must be a grouping
				foreach($value as $nestedGrouping => $groupFilter) {

					// Special Grouping operators. These will create condition expressions inside parentheses
					// where each expression is joined on either AND or OR based on the $operator
					if ($nestedGrouping === 'and' || $nestedGrouping === 'or') {
						$groupQuery->{$whereFn}(function ($subQuery) use ($rootQuery, $groupFilter, $nestedGrouping) {
							foreach($groupFilter as $childKey => $childValue) {
								$this->addFilterClause(
									$rootQuery,
									$childKey,
									$childValue,
									$nestedGrouping === 'and' ? self::GROUP_AND : self::GROUP_OR,
									$subQuery
								);
							}
						});
					} else {
						// If this is not an AND or OR grouping, it is a generic grouping with nested groups
						$this->addFilterClause($rootQuery, $nestedGrouping, $groupFilter, $grouping, $groupQuery);
					}
				}
			} else {
				if ($columnException) {
					throw $columnException;
				}

				throw new Exception("Filter group structure for key $key should be an array:\n\n" . json_encode($value));
			}
		}
	}

	/**
	 * @param Builder|\Illuminate\Database\Query\Builder $query
	 * @param                                            $key
	 * @param                                            $value
	 * @param string                                     $whereFn
	 */
	public function buildWhereIn($query, $key, $value, $whereFn)
	{
		if ($value) {
			//If one of the values is the string 'null', then we want to also include NULL values in our WHERE clause
			$arrayWithoutNull = array_filter($value, fn($v) => $v !== 'null' && $v !== null);

			if (count($arrayWithoutNull) < count($value)) {
				//If there are still values left in the array
				if ($arrayWithoutNull) {
					$query->{$whereFn}(function (Builder $query) use ($key, $arrayWithoutNull) {
						$query->whereIn($key, $arrayWithoutNull)
							->orWhereNull($key);
					});
				} else {
					$query->{$whereFn . 'Null'}($key);
				}
			} else {
				$query->{$whereFn . 'In'}($key, $value);
			}
		}
	}

	/**
	 * @param $query
	 * @param $operator
	 * @param $whereFn
	 * @param $key
	 * @param $value
	 * @return bool
	 */
	public function buildFilterType($query, $operator, $whereFn, $key, $value)
	{
		switch($operator) {
			/* @deprecated DO NOT USE raw query. this is very dangerous and will be removed */
			case 'raw':
				$query->{$whereFn . 'Raw'}('(' . $value . ')');
				break;

			// Checks if the value is either NULL or NOT NULL
			case 'null':
				$value ? $query->{$whereFn . 'Null'}($key) : $query->{$whereFn . 'NotNull'}($key);
				break;

			case 'from':
			case 'start':
			case '>=':
				$query->{$whereFn}($key, '>=', $value);
				break;

			case 'to':
			case 'end':
			case '<=':
				$query->{$whereFn}($key, '<=', $value);
				break;

			case '>':
				$query->{$whereFn}($key, '>', $value);
				break;

			case '<':
				$query->{$whereFn}($key, '<', $value);
				break;

			case 'like':
				$query->{$whereFn}($key, 'LIKE', '%' . $value . '%');
				break;

			case 'not like':
				$query->{$whereFn}($key, 'NOT LIKE', '%' . $value . '%');
				break;

			case '!=':
				if (is_array($value)) {
					$query->{$whereFn . 'NotIn'}($key, $value);
				} else {
					$query->{$whereFn}($key, '!=', $value);
				}
				break;

			case '=':
				if (is_array($value)) {
					$query->{$whereFn . 'In'}($key, $value);
				} else {
					$query->{$whereFn}($key, $value);
				}
				break;

			default:
				// return false if we do not recognize the operator
				return false;
		}

		// Return true if this was a known operator
		return true;
	}
}
