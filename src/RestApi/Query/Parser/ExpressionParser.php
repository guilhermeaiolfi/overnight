<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use InvalidArgumentException;
use ON\RestApi\Query\Node\AggregateExpression;
use ON\RestApi\Query\Node\AggregateFunction;
use ON\RestApi\Query\Node\DynamicVariableValue;
use ON\RestApi\Query\Node\ExpressionNode;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FunctionExpression;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\ValueNode;
use ON\RestApi\Query\Node\WildcardExpression;

final class ExpressionParser
{
	private const FUNCTIONS = ['year', 'month', 'day', 'hour', 'date'];

	public function parseExpression(string $value): ExpressionNode
	{
		$value = trim($value);

		if ($value === '*') {
			return new WildcardExpression();
		}

		if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/', $value, $matches)) {
			$function = strtolower($matches[1]);
			if (in_array($function, self::FUNCTIONS, true)) {
				return new FunctionExpression($function, [new FieldExpression($matches[2])]);
			}
		}

		return new FieldExpression($value);
	}

	public function parseAggregate(string $function, string $field): AggregateExpression
	{
		$distinct = false;
		$normalized = $function;
		if (str_ends_with($function, 'Distinct')) {
			$distinct = true;
			$normalized = substr($function, 0, -8);
		}

		$aggregateFunction = AggregateFunction::tryFrom(strtolower($normalized));
		if ($aggregateFunction === null) {
			throw new InvalidArgumentException("Unsupported aggregate function '{$function}'.");
		}

		return new AggregateExpression(
			$aggregateFunction,
			$this->parseExpression($field),
			null,
			$distinct
		);
	}

	public function parseValue(mixed $value): ValueNode
	{
		if (is_string($value) && str_starts_with($value, '$')) {
			return new DynamicVariableValue(substr($value, 1));
		}

		return new LiteralValue($value);
	}

	public function alias(string $value): string
	{
		$expression = $this->parseExpression($value);
		if ($expression instanceof FunctionExpression && isset($expression->arguments[0]) && $expression->arguments[0] instanceof FieldExpression) {
			return preg_replace('/[^a-zA-Z0-9_]/', '_', $expression->name . '_' . $expression->arguments[0]->field);
		}

		return preg_replace('/[^a-zA-Z0-9_]/', '_', $value);
	}
}
