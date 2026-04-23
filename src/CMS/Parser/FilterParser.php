<?php

declare(strict_types=1);

namespace ON\CMS\Parser;

use Cycle\Database\Injection\Parameter;
use ON\ORM\Definition\Registry;

class FilterParser
{
	public array $operators = [
		'_eq' => '=',
		'_neq' => '<>',
		'_lt' => '<',
		'_lte' => '<=',
		'_gt' => '>',
		'_gte' => '>=',
		'_in' => 'IN',
		'_nin' => 'NOT IN',
		'_null' => 'IS NULL',
		'_nnull' => 'IS NOT NULL',
		'_contains' => 'LIKE BINARY',
		'_icontains' => 'LIKE',
		'_ncontains' => 'NOT LIKE',
		'_starts_with' => 'LIKE BINARY',
		'_istarts_with' => 'LIKE',
		'_nstarts_with' => 'NOT LIKE BINARY',
		'_nistarts_with' => 'NOT LIKE',
		'_ends_with' => 'LIKE BINARY',
		'_iends_with' => 'LIKE BINARY',
		'_nends_with' => 'NOT LIKE BINARY',
		'_niends_with' => 'NOT LIKE',
		'_between' => 'BETWEEN',
		'_nbetween' => 'NOT BETWEEN',
		'_empty' => '=',
		'_nempty' => '<>',
	];

	public function __construct(
		protected Registry $registry
	) {

	}

	protected function _in(string $column, string $operator, mixed $value): array
	{
		return [
			$column => [
				'IN' => new Parameter($value),
			],
		];
	}

	protected function _nin(string $column, string $operator, mixed $value): array
	{
		return [
			$column => [
				'NOT IN' => new Parameter($value),
			],
		];
	}

	protected function _empty(string $column, string $operator, mixed $value): array
	{
		return [
			$column => [
				'=' => '',
			],
		];
	}

	protected function _operator(string $column, string $operator, mixed $value): array
	{
		return [
			$column => [
				$this->operators[$operator] => $value,
			],
		];
	}

	public function _or(string $column, string $operator, array $values, bool $negative = false): array
	{
		$flatten = [];
		foreach ($values as $key => $value) {
			$flatten[$key] = $this->flatten(".", $value);
		}

		return [
			'@OR' . ($negative ? ' NOT' : '') => $flatten,
		];
	}

	public function _and(string $column, string $operator, array $values, bool $negative = false): array
	{
		$flatten = [];
		foreach ($values as $key => $value) {
			$flatten[$key] = $this->flatten(".", $value);
		}

		return [
			'@AND' . ($negative ? ' NOT' : '') => $flatten,
		];
	}

	public function flatten($delimiter = '.', $items = null, $prepend = '')
	{
		$flatten = [];

		foreach ($items as $key => $value) {
			if (is_array($value) && ! empty($value) && is_string($key) && $key[0] != "_") {
				$flatten[] = $this->flatten($delimiter, $value, $prepend . $key . $delimiter);
			} else {
				$method = $key;
				if (! method_exists($this, $method)) {
					$method = "_operator";
				}
				$column = substr($prepend, 0, -1);
				$flatten[] = $this->$method($column, $key, $value);
			}
		}

		return array_merge(...$flatten);
	}

	/**
	 * {
	 *   "author": {
	 *      "_id": {
	 *          "_eq": 1
	 *      }
	 *		"vip": { // <-- relation
	 *			"_eq": true
	 *   	}
	 *   }
	 * }
	 */
	public function parse($array): array
	{
		return $this->flatten(".", $array);
	}
}
