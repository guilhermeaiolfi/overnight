<?php

declare(strict_types=1);

namespace ON\RestApi\Serialize;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Typecast\TypecastException;
use ON\ORM\Typecast\TypecastRegistry;
use ON\RestApi\Error\RestApiError;

final class CollectionSerializer
{
	public function __construct(
		private readonly TypecastRegistry $registry = new TypecastRegistry()
	) {
	}

	public function serialize(CollectionInterface $collection, array $phpRow): array
	{
		return $this->serializeRow($collection, $phpRow);
	}

	/**
	 * @param string|array<string, mixed> $payload
	 */
	public function unserialize(CollectionInterface $collection, string|array $payload, bool $partial = false): array
	{
		if (is_string($payload)) {
			if ($payload === '') {
				return [];
			}

			try {
				$decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $e) {
				throw RestApiError::invalidJson();
			}

			if (! is_array($decoded)) {
				throw RestApiError::invalidJson();
			}
		} else {
			$decoded = $payload;
		}

		return $this->unserializeRow($collection, $decoded, $partial);
	}

	private function serializeRow(CollectionInterface $collection, array $row): array
	{
		foreach ($collection->fields as $name => $field) {
			if (! array_key_exists($name, $row)) {
				continue;
			}

			$row[$name] = $this->serializeValue($row[$name], $field);
		}

		foreach ($collection->relations as $relationName => $relation) {
			if (! array_key_exists($relationName, $row)) {
				continue;
			}

			$row[$relationName] = $this->serializeRelationValue(
				$relation->getCollection(),
				$row[$relationName]
			);
		}

		return $row;
	}

	private function unserializeRow(CollectionInterface $collection, array $row, bool $partial = false): array
	{
		foreach ($collection->fields as $name => $field) {
			if ($partial && ! array_key_exists($name, $row)) {
				continue;
			}

			if (! array_key_exists($name, $row)) {
				continue;
			}

			$row[$name] = $this->unserializeValue($row[$name], $field);
		}

		foreach ($collection->relations as $relationName => $relation) {
			if (! array_key_exists($relationName, $row)) {
				continue;
			}

			$row[$relationName] = $this->unserializeRelationValue(
				$relation->getCollection(),
				$row[$relationName],
				$partial
			);
		}

		return $row;
	}

	private function serializeValue(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof \JsonSerializable) {
			return $value->jsonSerialize();
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTimeInterface::ATOM);
		}

		if (is_array($value)) {
			return array_map(
				fn (mixed $item): mixed => is_array($item) ? $item : $item,
				$value
			);
		}

		return $value;
	}

	private function unserializeValue(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		$customClass = $this->resolveCustomClass($field);
		if ($customClass !== null && is_string($value) && method_exists($customClass, 'fromString')) {
			try {
				return $customClass::fromString($value);
			} catch (\Throwable $e) {
				throw new TypecastException('Invalid field value.', $field->getName(), $e);
			}
		}

		try {
			$type = $field->getType();
		} catch (\Throwable) {
			return $value;
		}

		return match ($type) {
			'datetime', 'timestamp' => $this->parseDateTime($value, $field),
			'date' => $this->parseDate($value, $field),
			'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
			'json' => is_string($value)
				? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
				: $value,
			default => $value,
		};
	}

	private function parseDateTime(mixed $value, FieldInterface $field): \DateTimeImmutable
	{
		if ($value instanceof \DateTimeInterface) {
			return $value instanceof \DateTimeImmutable
				? $value
				: \DateTimeImmutable::createFromInterface($value);
		}

		try {
			return new \DateTimeImmutable((string) $value);
		} catch (\Throwable $e) {
			throw new TypecastException('Invalid datetime value.', $field->getName(), $e);
		}
	}

	private function parseDate(mixed $value, FieldInterface $field): \DateTimeImmutable
	{
		try {
			if ($value instanceof \DateTimeInterface) {
				return ($value instanceof \DateTimeImmutable
					? $value
					: \DateTimeImmutable::createFromInterface($value))->setTime(0, 0);
			}

			return (new \DateTimeImmutable((string) $value))->setTime(0, 0);
		} catch (\Throwable $e) {
			throw new TypecastException('Invalid date value.', $field->getName(), $e);
		}
	}

	/**
	 * @return class-string|null
	 */
	private function resolveCustomClass(FieldInterface $field): ?string
	{
		if (! $field->hasTypecast()) {
			return null;
		}

		$typecast = $field->getTypecast();
		if (is_string($typecast) && class_exists($typecast) && ! is_subclass_of($typecast, \ON\ORM\Typecast\TypecastInterface::class)) {
			return $typecast;
		}

		return null;
	}

	private function serializeRelationValue(CollectionInterface $target, mixed $value): mixed
	{
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
						$result[$action][$index] = $this->serializeRow($target, $item);
					}
				}
			}

			return $result;
		}

		if ($this->isAssociativeArray($value)) {
			return $this->serializeRow($target, $value);
		}

		foreach ($value as $index => $item) {
			if (is_array($item)) {
				$value[$index] = $this->serializeRow($target, $item);
			}
		}

		return $value;
	}

	private function unserializeRelationValue(CollectionInterface $target, mixed $value, bool $partial): mixed
	{
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
						$result[$action][$index] = $this->unserializeRow(
							$target,
							$item,
							$action === 'update'
						);
					}
				}
			}

			return $result;
		}

		if ($this->isAssociativeArray($value)) {
			return $this->unserializeRow($target, $value, $partial);
		}

		foreach ($value as $index => $item) {
			if (is_array($item)) {
				$value[$index] = $this->unserializeRow($target, $item, $partial);
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
}
