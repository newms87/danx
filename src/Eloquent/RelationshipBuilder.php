<?php

namespace Newms87\DanxLaravel\Eloquent;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;

class RelationshipBuilder
{
	/** @var Builder|QueryBuilder */
	protected $query;

	/** @var string The name of the relationship for the root table (ie: The FROM clause's table alias) */
	protected $rootRelationName;

	/** @var Relation The relationship class Object which contains the information requried to join */
	protected $relation;

	/** @var string The alias of the Relationship (this is what we will alias joined table as) */
	protected $relationName;

	/** @var string The table that is being joined */
	protected $relationTable;

	/** @var string The table we are joining to */
	protected $parentTable;

	/** @var string The alias for the parent table, so we can reference fields */
	protected $parentRelationName;

	/** @var array Additional where conditions on the JOIN clause */
	protected array $wheres;

	public function __construct(
		Builder $query,
		        $rootRelationName,
		        $relation,
		        $relationName,
		        $parentTable,
		        $parentRelationName,
		        $wheres = []
	)
	{
		$this->query              = $query;
		$this->rootRelationName   = $rootRelationName;
		$this->relation           = $relation;
		$this->relationName       = $relationName;
		$this->parentTable        = $parentTable;
		$this->parentRelationName = $parentRelationName;
		$this->wheres             = $wheres;

		$this->relationTable = $this->relation->getModel()->getTable();
	}

	/**
	 * Builds the Join clause on the query based on the type of relationship
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function performJoin()
	{
		if ($this->hasRelationshipOnQuery()) {
			return true;
		}

		// Resolve the type of relationship builder required
		// IMPORTANT: The Morph Relationship MUST be at the top as they extend the other relationships
		//            therefore they will match as an "instance of" those extended relations
		if ($this->relation instanceof MorphOne) {
			$this->performMorphOneJoin($this->relation);
		} elseif ($this->relation instanceof MorphMany) {
			$this->performMorphManyJoin($this->relation);
		} elseif ($this->relation instanceof MorphedByOne) {
			$this->performMorphedByOneJoin($this->relation);
		} elseif ($this->relation instanceof MorphToMany) {
			$this->performMorphToManyJoin($this->relation);
		} elseif ($this->relation instanceof MorphTo) {
			$this->performMorphToJoin($this->relation);
		} elseif ($this->relation instanceof BelongsTo) {
			$this->performBelongsToJoin($this->relation);
		} elseif ($this->relation instanceof HasMany) {
			$this->performHasManyJoin($this->relation);
		} elseif ($this->relation instanceof HasOne) {
			$this->performHasOneJoin($this->relation);
		} elseif ($this->relation instanceof BelongsToMany) {
			$this->performBelongsToManyJoin($this->relation);
		} else {
			throw new Exception('Unable to join tables on unknown relationship: ' . get_class($this->relation));
		}

		return true;
	}

	/**
	 * @param null $relationship
	 * @return bool
	 */
	public function hasRelationshipOnQuery($relationship = null)
	{
		return QueryUtils::hasJoin($this->query, $relationship ?: $this->aliasedRelationTable());
	}

	/**
	 * Renames the table being used in the relationship
	 * NOTE: This is here primarily for readability
	 *
	 * @return string
	 */
	public function aliasedRelationTable()
	{
		return $this->relationTable . ' as ' . $this->relationName;
	}

	/**
	 * Re-aliases the column so we are utilizing the name of the parent's alias
	 *
	 * @param $column
	 * @param $table
	 * @param $alias
	 * @return string|string[]|null
	 */
	public function aliasParentColumn($column)
	{
		if (strpos($column, '.') === false) {
			return "$this->parentRelationName.$column";
		} else {
			return preg_replace("/^$this->parentTable/", $this->parentRelationName, $column);
		}
	}

	/**
	 * Re-aliases the column so we are utilizing the name of the joining table's alias
	 *
	 * @param $column
	 * @return string|string[]|null
	 */
	public function aliasRelationColumn($column)
	{
		if (strpos($column, '.') === false) {
			return "$this->relationName.$column";
		} else {
			return preg_replace("/^$this->relationTable/", $this->relationName, $column);
		}
	}

	/**
	 * Checks if the Relationship's Model uses Soft Deletes so we know to ignore
	 * records that are soft deleted
	 */
	public function getSoftDeleteColumn()
	{
		$model = $this->relation->getModel();

		if (method_exists($model, 'getDeletedAtColumn')) {
			return $this->relationName . '.' . $model->getDeletedAtColumn();
		} else {
			return null;
		}
	}

	/**
	 * Forces the query to have a Group By clause so it will only return unique records.
	 * Unexpected duplicates can arise when joining on tables with a 1 to many relationship (ie: HasMany,
	 * BelongsToMany, MorphMany, etc.)
	 */
	public function forceGroupBy()
	{
		// Be sure to group by the root table ID otherwise we may end up with duplicate records (probably unintentionally)
		if (!$this->query->getQuery()->groups) {
			$this->query->groupBy([$this->rootRelationName . '.id']);
		}
	}

	/**
	 * Re-aliases the column so we are utilizing the name of the joining pivot table's  alias
	 *
	 * @param $column
	 * @return string|string[]|null
	 */
	public function aliasPivotColumn($column)
	{
		$alias = $this->pivotTableAlias();
		$table = $this->relation->getTable();

		if (strpos($column, '.') === false) {
			return "$alias.$column";
		} else {
			return preg_replace("/^$table/", $alias, $column);
		}
	}

	/**
	 * The Alias name of the pivot table (used by aliasedPivotTable to rename the relationship's pivot to avoid
	 * collisions)
	 *
	 * @return string
	 */
	public function pivotTableAlias()
	{
		return $this->relationName . '__' . $this->relation->getTable();
	}

	/**
	 * Renames the pivot table for the relationship so it will avoid collisions
	 * with other relationships using the same pivot table
	 *
	 * @return string
	 */
	public function aliasedPivotTable()
	{
		return $this->relation->getTable() . ' as ' . $this->pivotTableAlias();
	}

	/**
	 * @param $whereClause
	 * @return string
	 */
	public function getWhereClauseKey($whereClause)
	{
		return ($whereClause['type'] ?? '.') . ($whereClause['column'] ?? '.') . ($whereClause['operator'] ?? '.') . ($whereClause['value'] ?? '.') . ($whereClause['boolean'] ?? '.');
	}

	/**
	 * If the join where clause does not already have the required where clause, add it to the join where clauses
	 * @param QueryBuilder $join
	 * @return void
	 */
	public function mergeWheres(QueryBuilder $join)
	{
		foreach($this->wheres as $whereClause) {
			// Update the relationship name for the column depending on what nesting level we're at in the query
			$whereClause['column'] = preg_replace("/^[a-z0-9_]+\./", $this->relationName . '.', $whereClause['column']);
			if (!str_contains($whereClause['column'], '.')) {
				$whereClause['column'] = $this->relationName . '.' . $whereClause['column'];
			}

			// Make sure the where clause doesn't already exist on the join
			$key = $this->getWhereClauseKey($whereClause);
			foreach($join->wheres as $joinWhere) {
				if ($key === $this->getWhereClauseKey($joinWhere)) {
					continue 2;
				}
			}

			$join->wheres[] = $whereClause;
			$value          = $whereClause['value'] ?? null;
			if (!$value instanceof Expression) {
				$join->addBinding(is_array($value) ? head(Arr::flatten($value)) : $value);
			}
		}
	}

	/**
	 * Build a Morph One relationship join clause
	 *
	 * @param MorphOneOrMany $relation
	 */
	public function performMorphOneJoin(MorphOneOrMany $relation)
	{
		$this->query->leftJoin($this->aliasedRelationTable(),
			function (JoinClause $join) use ($relation) {
				$parentKey  = $relation->getQualifiedParentKeyName();
				$foreignKey = $relation->getQualifiedForeignKeyName();

				// Utilize the relation names instead of parent and child table names for parent, foreign keys
				$parentKey  = $this->aliasParentColumn($parentKey);
				$foreignKey = $this->aliasRelationColumn($foreignKey);

				$morphTypeKey = $this->relationName . '.' . $relation->getMorphType();

				$join->on($foreignKey, $parentKey)
					->where($morphTypeKey, $relation->getMorphClass());

				if ($softDeleteColumn = $this->getSoftDeleteColumn()) {
					$join->whereNull($softDeleteColumn);
				}

				$this->mergeWheres($join);
			});
	}

	/**
	 * Build a Morph Many relationship join clause
	 *
	 * @param MorphMany $relation
	 */
	public function performMorphManyJoin(MorphMany $relation)
	{
		$this->performMorphOneJoin($relation);

		$this->forceGroupBy();
	}

	/**
	 * Build a Morphed By One relationship join clause
	 */

	/**
	 * @param MorphToMany|MorphedByOne $relation
	 */
	public function performMorphedByOneJoin(MorphToMany $relation)
	{
		$relatedKey      = $this->relationName . '.' . $relation->getModel()->getKeyName();
		$parentKey       = $this->parentRelationName . '.' . $relation->getParent()->getKeyName();
		$foreignPivotKey = $relation->getQualifiedForeignPivotKeyName();
		$relatedPivotKey = $relation->getQualifiedRelatedPivotKeyName();
		$morphTypeKey    = $this->aliasPivotColumn($relation->getMorphType());

		$this->query->leftJoin($this->aliasedPivotTable(),
			function (JoinClause $join) use ($relation, $foreignPivotKey, $parentKey, $morphTypeKey) {
				$join->on(
					$this->aliasPivotColumn($foreignPivotKey),
					$parentKey
				)
					->where($morphTypeKey, $relation->getMorphClass());

				// TODO: Verify this is the correct behavior before enabling
				// $this->mergeWheres($join);
			});

		$this->performLeftJoin(
			$this->aliasedRelationTable(),
			$relatedKey,
			$this->aliasPivotColumn($relatedPivotKey)
		);
	}

	/**
	 * Joins the Relationship utilizing Soft Delete column when present
	 *
	 * @param $table
	 * @param $foreignKey
	 * @param $relatedKey
	 */
	public function performLeftJoin($table, $foreignKey, $relatedKey)
	{
		$this->query->leftJoin($table, function (JoinClause $join) use ($foreignKey, $relatedKey) {
			$join->on($foreignKey, $relatedKey);

			if ($softDeleteColumn = $this->getSoftDeleteColumn()) {
				$join->whereNull($softDeleteColumn);
			}

			// TODO: Verify this is the correct behavior before enabling
			//       $this->mergeWheres($join);
		});
	}

	/**
	 * Build a Morph To Many or a Morphed By Many (they are the same thing!) relationship join clause
	 *
	 * @param MorphToMany $relation
	 */
	public function performMorphToManyJoin(MorphToMany $relation)
	{
		$this->performMorphedByOneJoin($relation);

		$this->forceGroupBy();
	}

	/**
	 * Morph To relationships are not possible to build dynamically as they require a dynamically matched table name.
	 * This will not be possible is MySQL as a design of the system. To get around this problem you can create a
	 * BelongsTo relationship on the desired table using the morphable_id field and filtering by the morphable_type
	 * field to make sure you're grabbing the correct unique record (in case of using non-UUID fields).
	 *
	 * @param MorphTo $relation
	 *
	 * @throws Exception
	 */
	public function performMorphToJoin(MorphTo $relation)
	{
		throw new Exception("Morph To Relationships are not possible to dynamically build a join query on because they require dynamic table matching (based on the data in each record). This is not possible in SQL: $this->relationName");
	}

	/**
	 * Build the Belongs To relationship join clause
	 *
	 * @param BelongsTo $relation
	 */
	public function performBelongsToJoin(BelongsTo $relation)
	{
		// The ownerKey here is really the foreign key (the key on the child table) there's nothing wrong here but maybe
		// our naming convention (should probably be $joinTable and $originalTable) for the way we're stringing together these joins.
		// It is opposite of what HasMany would do because the key is on the opposite table
		$parentKey  = $relation->getQualifiedForeignKeyName();
		$foreignKey = $relation->getQualifiedOwnerKeyName();

		// Build the join the relationship on the query
		$this->performLeftJoin(
			$this->aliasedRelationTable(),
			$this->aliasRelationColumn($foreignKey),
			$this->aliasParentColumn($parentKey)
		);
	}

	/**
	 * Build a Has Many relationship join clause
	 *
	 * @param HasMany $relation
	 */
	public function performHasManyJoin(HasMany $relation)
	{
		$this->performHasOneJoin($relation);

		$this->forceGroupBy();
	}

	/**
	 * Build a Has One relationship join clause
	 *
	 * @param HasOneOrMany $relation
	 */
	public function performHasOneJoin(HasOneOrMany $relation)
	{
		$parentKey  = $relation->getQualifiedParentKeyName();
		$foreignKey = $relation->getQualifiedForeignKeyName();

		// We want to alias the child table as the relation name so we can add multiple relationships
		//  with this table
		$this->performLeftJoin(
			$this->aliasedRelationTable(),
			$this->aliasRelationColumn($foreignKey),
			$this->aliasParentColumn($parentKey)
		);
	}

	/**
	 * Build a Belongs To Many relationship join clause
	 *
	 * @param BelongsToMany $relation
	 */
	public function performBelongsToManyJoin(BelongsToMany $relation)
	{
		// Join on the Pivot table first
		$aliasedPivotTable = $this->aliasedPivotTable();

		// Only join if this exact relationship table has not already been joined
		if (!$this->hasRelationshipOnQuery($aliasedPivotTable)) {
			$parentKey       = $relation->getQualifiedParentKeyName();
			$foreignPivotKey = $relation->getQualifiedForeignPivotKeyName();

			$this->query->leftJoin(
				$aliasedPivotTable,
				$this->aliasPivotColumn($foreignPivotKey),
				$this->aliasParentColumn($parentKey)
			);
		}

		// Next we will join the relation table
		$relatedKey     = $this->relationName . '.id';
		$parentPivotKey = $relation->getQualifiedRelatedPivotKeyName();

		// We want to alias the child table as the relation name so we can add multiple relationships
		//  with this table
		$this->performLeftJoin(
			$this->aliasedRelationTable(),
			$this->aliasPivotColumn($parentPivotKey),
			$this->aliasParentColumn($relatedKey)
		);

		$this->forceGroupBy();
	}
}
