<?php

namespace Newms87\DanxLaravel\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class QueryUtils
{
	/**
	 * @param Builder|QueryBuilder $query
	 * @param string               $table
	 * @return bool
	 */
	public static function hasJoin($query, string $table): bool
	{
		return in_array($table, self::getJoinTables($query));
	}

	/**
	 * Returns join tables for $query
	 *
	 * @param Builder|QueryBuilder $query
	 * @return array
	 */
	public static function getJoinTables($query): array
	{
		$joinClauses = self::getBaseQuery($query)->joins;

		return $joinClauses ? array_column($joinClauses, 'table') : [];
	}

	/**
	 * @return QueryBuilder
	 */
	public static function getBaseQuery($query)
	{
		$baseQuery = $query;

		while(!($baseQuery instanceof QueryBuilder)) {
			$baseQuery = $baseQuery->getQuery();
		}

		return $baseQuery;
	}

	/**
	 * Return whether $table is mentioned in $query.
	 *
	 * Useful to see if you can mention $table in the query's where clause.
	 *
	 * @param Builder|QueryBuilder $query
	 * @param string               $table
	 * @return bool
	 */
	public static function hasTable($query, string $table): bool
	{
		if (self::getBaseQuery($query)->from === $table) {
			return true;
		}

		$joinTables = self::getJoinTables($query);

		return in_array($table, $joinTables);
	}

	/**
	 * Checks if there are any tables or aliases that match given $alias string
	 *
	 * @param Builder|QueryBuilder $query
	 * @param string               $alias
	 * @return bool
	 */
	public static function hasTableAlias($query, string $alias): bool
	{
		$tables   = self::getJoinTables($query);
		$tables[] = self::getBaseQuery($query)->from;

		foreach($tables as $table) {
			if ($alias === $table) {
				return true;
			}

			if (preg_match("/as $alias$/", $table)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Binds the parameters for a query builder
	 *
	 * @param Builder|QueryBuilder $builder
	 * @return null|string|string[]
	 */
	public static function bindParameters($builder)
	{
		$sql = $builder->toSql();

		$pdo = DB::getPdo();

		foreach($builder->getBindings() as $binding) {
			if (is_bool($binding)) {
				$value = $binding ? 'true' : 'false';
			} else {
				$value = $pdo->quote($binding);
			}

			$sql = preg_replace('/\?/', $value, $sql, 1);
		}

		return $sql;
	}
}
