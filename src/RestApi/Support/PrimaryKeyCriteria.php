<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
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
			$identity = $collection->getPrimaryKey()->extract($value);
			if ($identity !== null) {
				return $identity;
			}

			if (!$collection->getPrimaryKey()->isComposite() && array_is_list($value) && count($value) === 1) {
				return new PrimaryKeyValue($collection, [$collection->getPrimaryKey()->getFieldNames()[0] => $value[0]]);
			}

			throw new \InvalidArgumentException('Invalid primary key value array.');
		}

		if ($collection->getPrimaryKey()->isComposite()) {
			return $collection->getPrimaryKey()->getValueFromUrlId((string) $value);
		}

		return new PrimaryKeyValue($collection, [$collection->getPrimaryKey()->getFieldNames()[0] => $value]);
	}

	public static function build(CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): FilterNode
	{
		$identity = self::normalize($collection, $value);
		$filters = [];

		foreach ($collection->getPrimaryKey()->getFields() as $field) {
			$filters[] = new ComparisonFilter(
				new FieldExpression($field->getName()),
				ComparisonOperator::Eq,
				new LiteralValue($identity->getValue($field->getName()))
			);
		}

		return count($filters) === 1
			? $filters[0]
			: new LogicalFilter(LogicalOperator::And, $filters);
	}

	public static function applyWhere(object $query, CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): void
	{
		$identity = self::normalize($collection, $value);

		foreach ($collection->getPrimaryKey()->getFields() as $field) {
			$query->where($field->getColumn(), $identity->getValue($field->getName()));
		}
	}
}
