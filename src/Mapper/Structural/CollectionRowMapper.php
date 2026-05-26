<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\ORM\Definition\Collection\CollectionInterface;
use RuntimeException;

final class CollectionRowMapper implements MapperInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function defaultRepresentations(): array
	{
		return [
			'from' => StorageRepresentation::class,
			'as' => PhpRepresentation::class,
		];
	}

	public function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if (! is_array($from) || $to !== 'array' || $context->mapperClass !== self::class) {
			return false;
		}

		return $this->resolveCollection($context) !== null;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		$collection = $this->resolveCollection($context);
		if ($collection === null) {
			throw new RuntimeException('CollectionRowMapper requires a CollectionInterface argument.');
		}

		$fromRepresentation = $context->sourceRepresentation
			?? $this->defaultRepresentations()['from']
			?? StorageRepresentation::class;
		$toRepresentation = $context->outputRepresentation
			?? $this->defaultRepresentations()['as']
			?? PhpRepresentation::class;

		if ($fromRepresentation === $toRepresentation) {
			return $from;
		}

		if ($context->collection) {
			$result = [];
			foreach ($from as $item) {
				if (! is_array($item)) {
					throw new \InvalidArgumentException('Collection mapping expects a list of arrays.');
				}
				$result[] = $this->convertRow($fromRepresentation, $toRepresentation, $item, $collection);
			}

			return $result;
		}

		return $this->convertRow($fromRepresentation, $toRepresentation, $from, $collection);
	}

	private function convertRow(
		string $from,
		string $to,
		array $row,
		CollectionInterface $collection,
	): array {
		if ($from === $to) {
			return $row;
		}

		foreach ($collection->fields as $name => $field) {
			if (! array_key_exists($name, $row)) {
				continue;
			}

			$row[$name] = $this->gateway->to($from, $row[$name], $to, FieldContext::fromField($field));
		}

		foreach ($collection->relations as $relationName => $relation) {
			if (! array_key_exists($relationName, $row)) {
				continue;
			}

			$row[$relationName] = $this->convertRelationValue(
				$from,
				$to,
				$relation->getCollection(),
				$row[$relationName],
			);
		}

		return $row;
	}

	private function convertRelationValue(
		string $from,
		string $to,
		CollectionInterface $target,
		mixed $value,
	): mixed {
		if (! is_array($value)) {
			return $value;
		}

		if ($this->isRelationActionPayload($value)) {
			$result = $value;

			foreach (['create', 'update'] as $action) {
				if (! isset($result[$action]) || ! is_array($result[$action])) {
					continue;
				}

				foreach ($result[$action] as $index => $item) {
					if (is_array($item)) {
						$result[$action][$index] = $this->convertRow($from, $to, $item, $target);
					}
				}
			}

			return $result;
		}

		if ($this->isAssociativeArray($value)) {
			return $this->convertRow($from, $to, $value, $target);
		}

		foreach ($value as $index => $item) {
			if (is_array($item)) {
				$value[$index] = $this->convertRow($from, $to, $item, $target);
			}
		}

		return $value;
	}

	private function resolveCollection(MappingContext $context): ?CollectionInterface
	{
		if ($context->mapperClass === self::class && isset($context->mapperArgs[0])) {
			$collection = $context->mapperArgs[0];
			if ($collection instanceof CollectionInterface) {
				return $collection;
			}
		}

		return null;
	}

	private function isRelationActionPayload(array $value): bool
	{
		return $this->isAssociativeArray($value)
			&& (array_key_exists('create', $value)
				|| array_key_exists('update', $value)
				|| array_key_exists('delete', $value));
	}

	private function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}
}
