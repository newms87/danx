<?php

namespace Newms87\Danx\Eloquent;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

class CustomQueryBuilder
{
	const
		CARDINALITY_UNKNOWN = 'Unknown',
		CARDINALITY_SINGLE = 'Single',
		CARDINALITY_MULTIPLE = 'Multiple';

	/**
	 * @var Builder|QueryBuilder
	 */
	protected $query;

	/**
	 * FilterBuilder constructor.
	 *
	 * @param Builder|QueryBuilder $query
	 */
	public function __construct($query)
	{
		if ($query instanceof Relation) {
			$this->query = $query->getQuery();
		} else {
			$this->query = $query;
		}
	}

	/**
	 * @throws Exception
	 */
	public function build()
	{
		throw new Exception('Please implement the build method for you custom query builder');
	}

	/**
	 * Resets the SELECT clause so we can start from scratch
	 *
	 * @return $this
	 */
	public function clearSelect()
	{
		// NOTE: This must be set to an empty array (instead of null) to avoid the CustomerQueryBuilder from thinking it need to specify which columns to grab
		$this->getBaseQuery()->columns = [];

		return $this;
	}

	/**
	 * @return QueryBuilder
	 */
	public function getBaseQuery()
	{
		return QueryUtils::getBaseQuery($this->query);
	}

	/**
	 * Resets the GROUP BY clause
	 *
	 * @return $this
	 */
	public function clearGroupBy()
	{
		$this->getBaseQuery()->groups = null;

		return $this;
	}

	/**
	 * @param      $column
	 * @param null $as
	 * @param null $aggregate specify an aggregate function to apply to the column
	 * @return bool|string
	 *
	 * @throws Exception
	 */
	public function select($column, $as = null, $aggregate = null)
	{
		$qualifiedColumn = $this->addJoinTables($column);

		$isRaw = $this->isRawColumn($column);

		if (!$as && $isRaw) {
			$as = $this->resolveColumnRelationshipAlias($column);
		}

		// Apply the aggregate function to the selected column
		if ($aggregate) {
			$qualifiedColumn = "{$aggregate}($qualifiedColumn)";
			$isRaw           = true;
		}

		if ($isRaw) {
			if (!preg_match("/^['\"`]/", $as)) {
				$as = "`$as`";
			}

			$this->query->selectRaw($qualifiedColumn . ($as ? " as $as" : ''));
		} else {
			$this->query->addSelect($qualifiedColumn . ($as ? " as $as" : ''));
		}

		return $qualifiedColumn;
	}

	/**
	 * Builds a join relationship based on the relationship path given in $column relative to the Eloquent
	 * Query root Model (NOTE: This only works on Eloquent Builder objects)
	 * It will return the fully qualified column name so it the field can be referenced in the query
	 *
	 * @param string $column
	 * @param null   $params
	 * @return string The fully qualified column name
	 *
	 * @throws Exception
	 */
	public function addJoinTables($column, $params = null)
	{
		// Can only add join tables on eloquent Builder relationships
		if (!($this->query instanceof Builder || $this->query instanceof Relation)) {
			return $column;
		}

		// Clean up erroneous prefix and trailing .'s
		$column = trim($column, '.');

		// Check if this column has already been aliased, and therefore is intended to be a raw SQL statement
		// which does not require join tables
		if ($this->isRawColumn($column)) {
			// we still want to return the Qualified column name, which is the part before the ' as xxx' alias
			return preg_replace('/ as .*$/', '', $column);
		}

		/* @var Model $model The base Model to join relationships on / apply scopes to */
		$model = $this->query->getModel();

		// if the relationship is just a scope method, then apply the scope and do not apply additional filter clauses by returning false
		if ($this->applyModelScope($model, $column, $params)) {
			$this->formatSelect();

			return false;
		}

		if (strpos($column, '.')) {
			// Split the string on '.' operator to get the relationship path, the name after the final '.' in the path is the column name
			// If there is a -> operator, then we need to split on that as well as this is a JSON field reference
			// and needs to be kept as part of the column name
			$columnParts       = explode('->', $column, 2);
			$joinRelationships = explode('.', $columnParts[0]);
			if (!empty($columnParts[1])) {
				$joinRelationships[count($joinRelationships) - 1] .= '->' . $columnParts[1];
			}

			// The last element is the column name
			$field = array_pop($joinRelationships);

			// The root relation name will always remain the same (as opposed to the parentRelationName)
			$rootRelationName = $this->getRootRelationName();

			// The parent name will become the child as we start nesting our joined relationships
			$parentRelationName = $rootRelationName;
			$relationName       = '';

			// If the first relationship is the original table, we can just ignore this first one
			if ($joinRelationships[0] === $parentRelationName) {
				array_shift($joinRelationships);
			}

			$relationPath = '';

			foreach($joinRelationships as $joinRelationship) {
				$parentTable = null;
				$relation    = null;
				$wheres      = [];

				// Build the nested relationship name. NOTE: this is to avoid conflicting names on tables joined by different relationships
				$relationName .= ($relationName ? '__' : '') . $joinRelationship;
				$relationPath .= ($relationPath ? '.' : '') . $joinRelationship;

				// If the relationship exists, we want to update the model so we know our context for the next iteration
				// We also want to grab the relationship
				if ($model && method_exists($model, $joinRelationship)) {
					/* @var Relation|MorphOne $relation */
					try {
						$relation = $model->{$joinRelationship}();
					} catch(Exception $exception) {
						throw new Exception(
							'Failed to build relationship ' . get_class($model) . '@' . $joinRelationship . '. Does this relationship require the Model to be initialized w/ attributes? -- ' . $exception->getMessage(),
							2098,
							$exception
						);
					}

					$parentTable = $model->getTable();

					// Need to find the next relationship on the related model
					$model  = $relation->getModel();
					$wheres = $this->parseWheres($relationName, $relation, $relation->getQuery()->toBase()->wheres);
				} elseif ($model && $this->applyModelScope($model, $joinRelationship, $params, $relationPath)) {
					$this->formatSelect();

					// If the relationship ends with a scope, then the column we're filtering against does not apply as a filter
					return false;
				} else {
					// If this is a path that does not resolve to a Model, the model will be null so we do not try to use
					// Model methods (ie: scopes / relationships) intended for child Models
					$model = null;
				}

				// If the query does not already have this relationship, then we want to add it if we can
				if (!QueryUtils::hasTableAlias($this->query, $relationName)) {
					if (!$model) {
						throw new Exception("The relationship $relationName did not exist for the Model " . get_class($this->query->getModel()));
					}

					// If the relation is a Model the our name on this part of the path is the parent's name
					// and there is nothing to join
					if ($relation instanceof Model) {
						$relationName = $parentRelationName;
					} else {
						$joiner = new RelationshipBuilder(
							$this->query,
							$rootRelationName,
							$relation,
							$relationName,
							$parentTable,
							$parentRelationName,
							$wheres
						);

						// If the relationship exists for the Model, we need to join the tables required for the relationship,
						//  and do this for any nested relationships as well.
						$joiner->performJoin();
					}
				}

				$parentRelationName = $relationName;
			}

			// If the relationship existed, we need to make sure we set the column field to the field on the correct relationship
			$column = ($relationName ?: $parentRelationName) . '.' . $field;

			// If the relationship is just a scope on the Model, then we only need to apply the scope
			if ($model && $this->applyModelScope($model, $field, $params, $relationPath)) {
				$this->formatSelect();

				// If the relationship ends with a scope, then the column we're filtering against does not apply as a filter
				return false;
			}
		} else {
			// The backtick characters represent a literal field name
			if (strpos($column, '`') !== false) {
				return $column;
			}

			// We need to be sure to set the original relationship here otherwise there might be ambiguous fields
			if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
				$column = $model->getTable() . '.' . $column;
			} else {
				throw new Exception("The table `{$model->getTable()}` does not have the column `$column`");
			}
		}

		$this->formatSelect();

		// Make sure all field names are prefixed with table to avoid ambiguous fields
		foreach($this->getBaseQuery()->wheres as &$where) {
			if (!empty($where['column']) && strpos($where['column'], '.') === false) {
				$where['column'] = $this->query->getModel()->getTable() . '.' . $where['column'];
			}
		}

		// The qualified column name
		return $column;
	}

	/**
	 * Checks if the column should be treated as a raw SQL statement
	 *
	 * @param $column
	 * @return bool
	 */
	public function isRawColumn($column)
	{
		if (str_contains("->>'$.", $column)) {
			return true;
		}

		return preg_match('/[)(]/', $column) || strpos($column, ' as ') > 1;
	}

	/**
	 * Applies a scope method to a query and passes in params to the scope
	 *
	 * @param Model  $model
	 * @param        $column
	 * @param        $params
	 * @param string $rootRelation
	 * @return bool
	 */
	public function applyModelScope(Model $model, $column, $params, $rootRelation = '')
	{
		// If the relationship is just a scope on the Model, then we only need to apply the scope
		if (method_exists($model, 'scope' . $column)) {
			// Call the Scoped method to apply it to the query
			$model->{'scope' . $column}($this->query, $params[$column] ?? $params, $rootRelation);

			// If the relationship ends with a scope, then the column we're filtering against does not apply as a filter
			return true;
		}

		return false;
	}

	/**
	 * Ensures the select statement is prefixed w/ the alias if it is a select *
	 */
	public function formatSelect()
	{
		$selectStr = $this->getBaseQuery()->columns;

		if ((!$selectStr || $selectStr === '*') && !is_array($selectStr)) {
			// Make sure we are only grabbing the fields of the original table (not the relationship tables),
			// ambiguous fields like id will be overwritten otherwise!
			$this->query->select($this->query->getModel()->getTable() . '.*');
		}
	}

	/**
	 * Parse the relationship name for the root table (ie: the from clause) of the query builder
	 *
	 * @return string|string[]|null
	 */
	public function getRootRelationName()
	{
		$from = $this->getBaseQuery()->from;

		if (preg_match('/ as /', $from)) {
			return preg_replace('/.* as /', '', $from);
		}

		return $from;
	}

	/**
	 * Takes a column (ie: field relationship path - 'customer.buyer.id') and returns the relationship alias that would
	 * be used for its joined relationship tables by the CustomQueryBuilder. This is helpful when manually building
	 * queries that need to reference a resolved field for a different part of the query.
	 *
	 *
	 * @param $column
	 * @return string
	 */
	public function resolveColumnRelationshipAlias($column)
	{
		$column = trim($column, '.');

		if ($this->isRawColumn($column)) {
			return preg_replace('/^.* as /', '', $column);
		} elseif (strpos($column, '.') === false) {
			return $this->getRootRelationName() . '.' . $column;
		} else {
			$segments = explode('.', $column);

			$field = array_pop($segments);

			return implode('__', $segments) . '.' . $field;
		}
	}

	/**
	 * @param      $column
	 * @param null $as
	 * @return $this
	 *
	 * @throws Exception
	 */
	public function unSelect($column, $as = null)
	{
		// We need to make sure the column name matches how it is set in the Query columns array exactly
		$columnStr = $this->resolveColumnRelationshipAlias($column) . ($as ? " as $as" : '');

		$columns = $this->getBaseQuery()->columns;

		$index = array_search($columnStr, $columns);

		if ($index !== false) {
			array_splice($columns, $index, 1);
			$this->getBaseQuery()->columns = $columns;
		}

		return $this;
	}

	/**
	 * Joins a related table with the name aliased as the relation
	 *
	 * @param $relation - Either the relation name or an array of relation names
	 * @return static
	 *
	 * @throws Exception
	 */
	public function joinRelation($relation)
	{
		$relations = (array)$relation;

		foreach($relations as $relationName) {
			$this->addJoinTables($relationName . '.id');
		}

		return $this;
	}

	/**
	 * Alias for addJoinTables
	 *
	 * @param $column
	 * @return false|string
	 *
	 * @throws Exception
	 */
	public function joinRelationColumn($column)
	{
		return $this->addJoinTables($column);
	}

	/**
	 * @param $column
	 * @return bool
	 */
	public function hasRelationshipJoined($column)
	{
		$tableAlias = $this->getColumnTableAlias($column);

		return QueryUtils::hasTableAlias($this->getBaseQuery(), $tableAlias);
	}

	/**
	 * Returns the expected table alias for the column
	 *
	 * @param $column
	 * @return string
	 */
	public function getColumnTableAlias($column)
	{
		$path = $this->getRelationPathForColumn($column);

		return implode('__', $path);
	}

	/**
	 * Returns the relationship path to the field of the column relative to the root Model.
	 *
	 * NOTE: this ignores the root model part of the path and the field name, only returns the parts of the path in
	 * between
	 *
	 * @param $column
	 * @return false|string[]
	 */
	public function getRelationPathForColumn($column)
	{
		// Normalize the string to be dot notation
		$column = str_replace('__', '.', $column);

		// Split the path into parts
		$path = explode('.', $column);

		// The last item in the column path is the field, which we don't want
		array_pop($path);

		// Remove the first part of path if it is already the root path
		if ($path && $path[0] === $this->getRootRelationName()) {
			array_shift($path);
		}

		return $path;
	}

	/**
	 * Resolves the relationship join type to see if it will join by a single record or multiple records
	 *
	 * @param $column
	 * @return int
	 */
	public function resolveCardinality($column)
	{
		if (!($this->query instanceof Builder || $this->query instanceof Relation)) {
			return $column;
		}

		// The root model to resolve relationships / scopes
		$model = $this->query->getModel();

		$relationNames = $this->getRelationPathForColumn($column);

		if ($relationNames) {
			foreach($relationNames as $relationName) {
				if ($model && method_exists($model, $relationName)) {
					$relation = $model->$relationName();

					if (
						$relation instanceof BelongsToMany ||
						$relation instanceof HasMany ||
						$relation instanceof MorphMany ||
						$relation instanceof MorphToMany
					) {
						return self::CARDINALITY_MULTIPLE;
					}

					$model = $relation->getModel();
				} else {
					// If the relationship does not exist (or is a scope) then we cannot determine
					// the cardinality here
					return self::CARDINALITY_UNKNOWN;
				}
			}
		} else {
			// If the column in a scope, we don't know what the cardinality is, but it is better to handle it as a Multiple Cardinality since it might be
			if (method_exists($model, 'scope' . $column)) {
				return self::CARDINALITY_MULTIPLE;
			}
		}

		// If we were able to identify all parts of the relationship and there were no one to many type joins, then the cardinality must be one to one
		return self::CARDINALITY_SINGLE;
	}

	/**
	 * Convert a QueryBuilder wheres clause to an array we can use to build a query
	 * @param $relationName
	 * @param $idColumn
	 * @param $whereClauses
	 * @return array
	 */
	public function parseWheres($relationName, $relation, $whereClauses)
	{
		$wheres        = [];
		$ignoreColumns = [];

		if ($relation instanceof MorphOne) {
			$ignoreColumns[] = $relation->getQualifiedForeignKeyName();
			$ignoreColumns[] = $relation->getQualifiedMorphType();
		} elseif ($relation instanceof MorphMany) {
			$ignoreColumns[] = $relation->getQualifiedForeignKeyName();
			$ignoreColumns[] = $relation->getQualifiedMorphType();
		} elseif ($relation instanceof MorphToMany) {
			$ignoreColumns[] = $relation->getQualifiedForeignPivotKeyName();
			$ignoreColumns[] = $relation->getQualifiedRelatedPivotKeyName();
		} elseif ($relation instanceof BelongsToMany) {
			$ignoreColumns[] = $relation->getQualifiedForeignPivotKeyName();
			$ignoreColumns[] = $relation->getQualifiedRelatedPivotKeyName();
		} elseif ($relation instanceof HasMany || $relation instanceof HasOne) {
			$ignoreColumns[] = $relation->getQualifiedForeignKeyName();
		} elseif ($relation instanceof BelongsTo) {
			$ignoreColumns[] = $relation->getQualifiedForeignKeyName();
			$ignoreColumns[] = $relation->getQualifiedOwnerKeyName();
		} elseif ($relation instanceof HasManyThrough) {
			$ignoreColumns[] = $relation->getQualifiedFirstKeyName();
			$ignoreColumns[] = $relation->getQualifiedForeignKeyName();
			$ignoreColumns[] = $relation->getQualifiedLocalKeyName();
		}
		$ignoreColumns[] = $relation->getQualifiedParentKeyName();

		foreach($whereClauses as $whereClause) {
			// Ignore the ID columns
			if (!in_array($whereClause['column'], $ignoreColumns)) {
				$wheres[] = $whereClause;
			}
		}

		return $wheres;
	}
}
