<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;

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

	/**
	 * @return non-empty-array<string, mixed>
	 */
	public static function build(CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): array
	{
		$identity = self::normalize($collection, $value);
		$criteria = [];

		foreach (PrimaryKey::of($collection)->getFields() as $field) {
			$criteria[$field->getName()] = $identity->value($field->getName());
		}

		return $criteria;
	}

	public static function applyWhere(object $query, CollectionInterface $collection, PrimaryKeyValue|array|string|int|float $value): void
	{
		$identity = self::normalize($collection, $value);

		foreach (PrimaryKey::of($collection)->getFields() as $field) {
			$query->where($field->getColumn(), $identity->value($field->getName()));
		}
	}
}
