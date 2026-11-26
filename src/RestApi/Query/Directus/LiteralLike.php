<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Directus;

use ON\Data\Query\QueryFunction\CompiledExpression;
use ON\Data\Query\QueryFunction\FunctionArgumentException;
use ON\Data\Query\QueryFunction\FunctionArguments;
use ON\Data\Query\QueryFunction\FunctionCompilationContextInterface;
use ON\Data\Query\QueryFunction\QueryFunctionInterface;

/**
 * Compiles search patterns with an explicit escape character on SQL backends.
 *
 * @internal
 */
final class LiteralLike implements QueryFunctionInterface
{
	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		if ($arguments->count() !== 3) {
			throw FunctionArgumentException::arity(self::class, 3, $arguments->count());
		}

		$field = $context->compile($arguments->expression(0));
		$pattern = $context->compile($arguments->expression(1));
		$operator = $arguments->literal(2) === true ? 'NOT LIKE' : 'LIKE';

		return $context->sql(
			sprintf("CASE WHEN %s %s %s ESCAPE '!' THEN 1 ELSE 0 END", $field->getSql(), $operator, $pattern->getSql()),
			array_merge($field->getParameters(), $pattern->getParameters()),
		);
	}
}
