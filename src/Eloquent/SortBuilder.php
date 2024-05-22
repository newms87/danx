<?php

namespace Newms87\DanxLaravel\Eloquent;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SortBuilder extends CustomQueryBuilder
{
	const
		ORDER_ASC = 'asc',
		ORDER_DESC = 'desc';

	const ORDER_ALIASES = [
		'asc'        => self::ORDER_ASC,
		'desc'       => self::ORDER_DESC,
		'ascending'  => self::ORDER_ASC,
		'descending' => self::ORDER_DESC,
		'a-z'        => self::ORDER_ASC,
		'z-a'        => self::ORDER_DESC,
	];

	protected $sort;

	/**
	 * FilterBuilder constructor.
	 *
	 * @param Builder|\Illuminate\Database\Query\Builder $query
	 * @param array                                      $filter
	 */
	public function __construct($query, $sort = null)
	{
		parent::__construct($query);

		$this->sort = $sort;
	}

	/**
	 * Builds the WHERE conditions for the SQL query based on the $filter
	 *
	 * @throws Exception
	 */
	public function build()
	{
		if ($this->sort) {
			foreach($this->sort as $name => $order) {
				$expression = null;
				// If $order is an object, we assume it is of the format {column: 'name', order: 'asc'}
				if (is_string($order)) {
					$column = $name;
				} elseif (is_object($order)) {
					$column     = $order->column;
					$expression = $order->expression ?? null;
					$order      = $order->order ?? self::ORDER_ASC;
				} elseif (is_array($order)) {
					$column     = $order['column'];
					$expression = $order['expression'] ?? null;
					$order      = $order['order'] ?? self::ORDER_ASC;
				} else {
					throw new Exception("The Sorting Order and Column have not been set for $name: " . json_encode($order));
				}

				$this->buildOrderBy($column, $order, $expression);
			}
		}
	}

	/**
	 *  Build an SQL Order By clause
	 *
	 * @param string $column     the column (or expression) to sort by
	 * @param string $order      the ordering (ie: asc or desc)
	 * @param null   $expression if provided, use this as the sort expression instead of the column (NOTE: column still
	 *                           useful to provide the related tables)
	 *
	 * @throws Exception
	 */
	public function buildOrderBy($column, $order = self::ORDER_ASC, $expression = null)
	{
		// Resolve any tables that need to be joined, and get the column name for that relationship
		$column = $this->addJoinTables($column);

		// Find the value for the order alias (default to Ascending)
		$order = self::ORDER_ALIASES[$order] ?? self::ORDER_ASC;

		if ($expression) {
			$this->query->orderBy(DB::raw($expression), $order);
		} else {
			// If the column name appears to be an expression instead of a field name, use the raw expression
			if (preg_match('/[\\(\\)\\s]/', $column) || strpos($column, '`') !== false) {
				$this->query->orderBy(DB::raw($column), $order);
			} else {
				$this->query->orderBy($column, $order);
			}
		}
	}
}
