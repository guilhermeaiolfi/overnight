<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\LogicalOperator;

final class PrimaryKeyCriteria
{
	public static function normalize(CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): PrimaryKeyValue
	{
		if ($value instanceof PrimaryKeyValue) {
			return $value;
		}

		if (is_array($value)) {
			$identity = PrimaryKey::of($collection)->extractFromInput($value);
			if ($identity !== null) {
				return $identity;
			}

			if (! PrimaryKey::of($collection)->isComposite() && array_is_list($value) && count($value) === 1) {
				return new PrimaryKeyValue($collection, [PrimaryKey::of($collection)->getFieldNames()[0] => $value[0]]);
			}

			throw new InvalidArgumentException('Invalid primary key value array.');
		}

		if (PrimaryKey::of($collection)->isComposite()) {
			return PrimaryKey::of($collection)->getValueFromUrlId((string) $value);
		}

		return new PrimaryKeyValue($collection, [PrimaryKey::of($collection)->getFieldNames()[0] => $value]);
	}

	public static function build(CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): FilterNode
	{
		$identity = self::normalize($collection, $value);
		$filters = [];

		foreach (PrimaryKey::of($collection)->getFields() as $field) {
			$filters[] = new ComparisonFilter(
				new FieldExpression($field->getName()),
				ComparisonOperator::Eq,
				new LiteralValue($identity->value($field->getName()))
			);
		}

		return count($filters) === 1
			? $filters[0]
			: new LogicalFilter(LogicalOperator::And, $filters);
	}

	public static function applyWhere(object $query, CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): void
	{
		$identity = self::normalize($collection, $value);

		foreach (PrimaryKey::of($collection)->getFields() as $field) {
			$query->where($field->getColumn(), $identity->value($field->getName()));
		}
	}
}
