<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Query\QueryParameters;
use ON\ORM\Definition\Collection\CollectionInterface;

class SqlExpressionBuilder
{
	private const FUNCTIONS = ['year', 'month', 'day', 'hour', 'date'];

	public function __construct(
		protected DatabaseInterface $database
	) {
	}

	public function queryField(CollectionInterface $collection, string $value, string $tableAlias): ?FragmentInterface
	{
		if ($collection->fields->has($value)) {
			return $this->field($collection, $value, $tableAlias);
		}

		return $this->functionField($collection, $value, $tableAlias);
	}

	public function field(CollectionInterface $collection, string $field, string $tableAlias): ?Expression
	{
		if (!$collection->fields->has($field)) {
			return null;
		}

		return new Expression($tableAlias . '.' . $collection->fields->get($field)->getColumn());
	}

	public function functionField(CollectionInterface $collection, string $value, string $tableAlias): ?FragmentInterface
	{
		$parsed = $this->parseFunction($value);
		if ($parsed === null) {
			return null;
		}

		[$function, $field] = $parsed;
		$column = $this->field($collection, $field, $tableAlias);
		if ($column === null) {
			return null;
		}

		$compiledColumn = $this->compile($column);

		return new Fragment($this->functionSql($function, $compiledColumn));
	}

	public function selectedField(CollectionInterface $collection, string $value, string $tableAlias, string $alias): ?FragmentInterface
	{
		if ($collection->fields->has($value)) {
			return new Expression($tableAlias . '.' . $collection->fields->get($value)->getColumn() . ' AS ' . $alias);
		}

		$expression = $this->functionField($collection, $value, $tableAlias);

		return $expression === null ? null : $this->aliased($expression, $alias);
	}

	public function aggregate(
		string $function,
		CollectionInterface $collection,
		string $field,
		string $tableAlias,
		string $alias,
		bool $distinct = false
	): ?FragmentInterface {
		if ($field === '*' && strtolower($function) === 'count' && !$distinct) {
			return new Expression('COUNT(*) AS ' . $alias);
		}

		if ($collection->fields->has($field)) {
			$column = $tableAlias . '.' . $collection->fields->get($field)->getColumn();
			$distinctSql = $distinct ? 'DISTINCT ' : '';

			return new Expression(strtoupper($function) . '(' . $distinctSql . $column . ') AS ' . $alias);
		}

		$expression = $this->functionField($collection, $field, $tableAlias);
		if ($expression === null) {
			return null;
		}

		$distinctSql = $distinct ? 'DISTINCT ' : '';

		return new Fragment(
			strtoupper($function) . '(' . $distinctSql . $this->compile($expression) . ') AS ' . $this->identifier($alias)
		);
	}

	public function aliased(FragmentInterface $expression, string $alias): FragmentInterface
	{
		if ($expression instanceof Expression) {
			return new Expression($expression->getTokens()['expression'] . ' AS ' . $alias);
		}

		return new Fragment($this->compile($expression) . ' AS ' . $this->identifier($alias));
	}

	public function isFunction(string $value): bool
	{
		return $this->parseFunction($value) !== null;
	}

	public function alias(string $value): string
	{
		$parsed = $this->parseFunction($value);

		return preg_replace('/[^a-zA-Z0-9_]/', '_', $parsed === null ? $value : $parsed[0] . '_' . $parsed[1]);
	}

	public function identifier(string $identifier): string
	{
		return $this->database->getDriver()->getQueryCompiler()->quoteIdentifier($identifier);
	}

	public function compile(FragmentInterface $expression): string
	{
		return $this->database->getDriver()->getQueryCompiler()->compile(
			new QueryParameters(),
			$this->database->getPrefix(),
			$expression
		);
	}

	protected function parseFunction(string $value): ?array
	{
		if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/', trim($value), $matches)) {
			return null;
		}

		$function = strtolower($matches[1]);
		if (!in_array($function, self::FUNCTIONS, true)) {
			return null;
		}

		return [$function, $matches[2]];
	}

	protected function functionSql(string $function, string $column): string
	{
		return match (strtolower($this->database->getType())) {
			'sqlite' => match ($function) {
				'year' => "CAST(strftime('%Y', {$column}) AS INTEGER)",
				'month' => "CAST(strftime('%m', {$column}) AS INTEGER)",
				'day' => "CAST(strftime('%d', {$column}) AS INTEGER)",
				'hour' => "CAST(strftime('%H', {$column}) AS INTEGER)",
				'date' => "date({$column})",
			},
			'postgres', 'pgsql' => match ($function) {
				'year' => "EXTRACT(YEAR FROM {$column})",
				'month' => "EXTRACT(MONTH FROM {$column})",
				'day' => "EXTRACT(DAY FROM {$column})",
				'hour' => "EXTRACT(HOUR FROM {$column})",
				'date' => "DATE({$column})",
			},
			'sqlserver', 'sqlsrv' => match ($function) {
				'year' => "DATEPART(year, {$column})",
				'month' => "DATEPART(month, {$column})",
				'day' => "DATEPART(day, {$column})",
				'hour' => "DATEPART(hour, {$column})",
				'date' => "CAST({$column} AS date)",
			},
			default => match ($function) {
				'year' => "YEAR({$column})",
				'month' => "MONTH({$column})",
				'day' => "DAY({$column})",
				'hour' => "HOUR({$column})",
				'date' => "DATE({$column})",
			},
		};
	}
}
