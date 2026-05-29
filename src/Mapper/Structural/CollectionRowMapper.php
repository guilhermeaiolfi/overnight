<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldConversionCoordinator;
use ON\Mapper\Conversion\Resolver\CollectionFieldResolver;
use ON\Mapper\ConversionGateway;
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

	public static function defaultRepresentations(): array
	{
		return [
			'from' => StorageRepresentation::class,
			'as' => PhpRepresentation::class,
		];
	}

	public static function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if (! is_array($from) || $to !== 'array' || $context->mapperClass !== self::class) {
			return false;
		}

		return self::resolveCollection($context) !== null;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		$collection = self::resolveCollection($context);
		if ($collection === null) {
			throw new RuntimeException('CollectionRowMapper requires a CollectionInterface argument.');
		}

		$fromRepresentation = $context->sourceRepresentation
			?? self::defaultRepresentations()['from']
			?? StorageRepresentation::class;
		$toRepresentation = $context->outputRepresentation
			?? self::defaultRepresentations()['as']
			?? PhpRepresentation::class;

		if ($fromRepresentation === $toRepresentation) {
			return $from;
		}

		$conversion = (new FieldConversionCoordinator($this->gateway))
			->register(new CollectionFieldResolver())
			->registerConfiguredResolvers($context);
		$direction = $this->directionForRepresentations($context);

		if ($context->collection) {
			$result = [];
			foreach ($from as $item) {
				if (! is_array($item)) {
					throw new \InvalidArgumentException('Collection mapping expects a list of arrays.');
				}
				$result[] = $this->convertRow($item, $collection, $context, $conversion, $direction);
			}

			return $result;
		}

		return $this->convertRow($from, $collection, $context, $conversion, $direction);
	}

	private function directionForRepresentations(MappingContext $context): ConversionDirection
	{
		if ($context->sourceRepresentation !== null
			&& $context->sourceRepresentation !== PhpRepresentation::class) {
			return ConversionDirection::Inbound;
		}

		return ConversionDirection::Outbound;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function convertRow(
		array $row,
		CollectionInterface $collection,
		MappingContext $context,
		FieldConversionCoordinator $conversion,
		ConversionDirection $direction,
	): array {
		foreach ($collection->fields as $name => $_field) {
			if (! array_key_exists($name, $row)) {
				continue;
			}

			$row[$name] = $this->convertFieldValue(
				$context,
				$conversion,
				$name,
				$row[$name],
				$direction,
			);
		}

		foreach ($collection->relations as $relationName => $relation) {
			if (! array_key_exists($relationName, $row)) {
				continue;
			}

			$row[$relationName] = $this->convertRelationValue(
				$relation->getCollection(),
				$row[$relationName],
				$context,
				$conversion,
				$direction,
			);
		}

		return $row;
	}

	private function convertFieldValue(
		MappingContext $context,
		FieldConversionCoordinator $conversion,
		string $name,
		mixed $value,
		ConversionDirection $direction,
	): mixed {
		$resolved = $conversion->resolveField($context, $name, $name, $value, $direction);
		if ($resolved === null) {
			return $value;
		}

		return $conversion->convertScalar($value, $resolved, $context, $direction);
	}

	private function convertRelationValue(
		CollectionInterface $target,
		mixed $value,
		MappingContext $context,
		FieldConversionCoordinator $conversion,
		ConversionDirection $direction,
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
						$result[$action][$index] = $this->convertRow(
							$item,
							$target,
							$context,
							$conversion,
							$direction,
						);
					}
				}
			}

			return $result;
		}

		if ($this->isAssociativeArray($value)) {
			return $this->convertRow($value, $target, $context, $conversion, $direction);
		}

		foreach ($value as $index => $item) {
			if (is_array($item)) {
				$value[$index] = $this->convertRow($item, $target, $context, $conversion, $direction);
			}
		}

		return $value;
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

	private static function resolveCollection(MappingContext $context): ?CollectionInterface
	{
		foreach ($context->args as $arg) {
			if ($arg instanceof CollectionInterface) {
				return $arg;
			}
		}

		$first = $context->args[0] ?? null;

		return $first instanceof CollectionInterface ? $first : null;
	}
}
