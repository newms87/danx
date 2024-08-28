<?php

namespace Newms87\Danx\Traits;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Eloquent\FilterBuilder;

/**
 * Search the Model by a list of fields using like comparisons against a list of comma separated values
 * in an input string
 *
 * @mixin Model
 */
trait KeywordSearchTrait
{
	/**
	 * Apply a search on the Model's query
	 *
	 * @param Builder $query
	 * @param         $keywords
	 * @param null    $fields
	 * @return Builder
	 *
	 * @throws Exception
	 */
	public function scopeKeywords(Builder $query, $keywords, $fields = null)
	{
		$fields = $fields ?: $this->keywordFields;
		if (empty($fields)) {
			throw new Exception('You must add a list of fields to the protected $keywordFields attribute on ' . static::class);
		}

		if ($keywords) {
			$terms = explode(',', $keywords);

			$termFilters = [];

			foreach($terms as $term) {
				$fieldFilter = [];

				$term = trim($term);

				// If the search term is prefixed w/ !, then we want to negate the term
				if (str_starts_with($term, '!')) {
					$termGroupOperator = 'and';
					$operator          = 'not like';
					$termExpression    = substr($term, 1);
				} else {
					$termGroupOperator = 'or';
					$operator          = 'like';
					$termExpression    = $term;
				}

				foreach($fields as $field) {
					$fieldFilter[$field] = [$operator => $termExpression];
				}

				$termFilters['term' . $term] = [$termGroupOperator => $fieldFilter];
			}

			$filter = ['keywordTerms' => ['and' => $termFilters]];

			$builder = new FilterBuilder();
			$builder->applyFilter($query, $filter);
		}

		return $query;
	}
}
