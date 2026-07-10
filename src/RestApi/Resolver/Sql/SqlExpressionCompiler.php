<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Query\QueryParameters;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\AggregateExpression;
use ON\RestApi\Query\Node\AggregateFunction;
use ON\RestApi\Query\Node\ExpressionNode;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FunctionExpression;
use ON\RestApi\Query\Node\ValueNode;
use ON\RestApi\Query\Node\WildcardExpression;

class SqlExpressionCompiler
{
	private const FUNCTIONS = ['year', 'month', 'day', 'hour', 'date'];

	public function __construct(
		protected DatabaseInterface $database
	) {
	}

	public function value(CollectionInterface $collection, ExpressionNode $expression, string $tableAlias): ?FragmentInterface
	{
		if ($expression instanceof FieldExpression) {
			return $this->fieldExpression($collection, $expression->field, $tableAlias);
		}

		if ($expression instanceof FunctionExpression) {
			return $this->functionExpression($collection, $expression, $tableAlias);
		}

		if ($expression instanceof WildcardExpression) {
			return new Expression('*');
		}

		return null;
	}

	public function select(CollectionInterface $collection, ExpressionNode $expressionNode, string $tableAlias, string $alias): ?FragmentInterface
	{
		$expression = $this->value($collection, $expressionNode, $tableAlias);

		return $expression === null ? null : $this->aliased($expression, $alias);
	}

	public function aggregate(
		CollectionInterface $collection,
		AggregateExpression $aggregate,
		string $tableAlias,
		string $alias
	): ?FragmentInterface {
		$function = $aggregate->function->value;

		if ($aggregate->argument instanceof WildcardExpression && $aggregate->function === AggregateFunction::Count && ! $aggregate->distinct) {
			return new Expression('COUNT(*) AS ' . $alias);
		}

		$expression = $this->value($collection, $aggregate->argument, $tableAlias);
		if ($expression === null) {
			return null;
		}

		$distinctSql = $aggregate->distinct ? 'DISTINCT ' : '';

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

	public function alias(ExpressionNode $expression): string
	{
		if ($expression instanceof FieldExpression) {
			return $this->safeAlias($expression->field);
		}

		if ($expression instanceof FunctionExpression) {
			return $this->safeAlias($expression->name . '_' . implode('_', array_map(
				fn (ExpressionNode|ValueNode $argument) => $argument instanceof ExpressionNode
					? $this->alias($argument)
					: (string) $argument->value(),
				$expression->arguments
			)));
		}

		if ($expression instanceof WildcardExpression) {
			return 'all';
		}

		return $this->safeAlias($expression::class);
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

	protected function fieldExpression(CollectionInterface $collection, string $field, string $tableAlias): ?Expression
	{
		if (! $collection->fields->has($field)) {
			return null;
		}

		return new Expression($tableAlias . '.' . $collection->fields->get($field)->getColumn());
	}

	protected function functionExpression(CollectionInterface $collection, FunctionExpression $expression, string $tableAlias): ?FragmentInterface
	{
		$function = strtolower($expression->name);
		if (! in_array($function, self::FUNCTIONS, true)) {
			return null;
		}

		$argument = $expression->arguments[0] ?? null;
		if (! $argument instanceof FieldExpression) {
			return null;
		}

		$column = $this->fieldExpression($collection, $argument->field, $tableAlias);
		if ($column === null) {
			return null;
		}
		$compiledColumn = $this->compile($column);

		return new Fragment($this->functionSql($function, $compiledColumn));
	}

	protected function safeAlias(string $value): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $value);
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
