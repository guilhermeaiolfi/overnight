<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;

final class CollectionTypecast
{
	public function __construct(
		private readonly TypecastRegistry $registry = new TypecastRegistry()
	) {
	}

	public function toPhp(CollectionInterface $collection, array $data): array
	{
		foreach ($collection->fields as $name => $field) {
			if (! array_key_exists($name, $data)) {
				continue;
			}

			$data[$name] = $this->applyToPhp($field, $data[$name]);
		}

		foreach ($collection->relations as $relationName => $relation) {
			if (! array_key_exists($relationName, $data)) {
				continue;
			}

			$data[$relationName] = $this->toPhpRelationValue(
				$relation->getCollection(),
				$data[$relationName]
			);
		}

		return $data;
	}

	public function fromPhp(CollectionInterface $collection, array $data, bool $partial = false): array
	{
		foreach ($collection->fields as $name => $field) {
			if ($partial && ! array_key_exists($name, $data)) {
				continue;
			}

			if (! array_key_exists($name, $data)) {
				continue;
			}

			$data[$name] = $this->applyFromPhp($field, $data[$name]);
		}

		foreach ($collection->relations as $relationName => $relation) {
			if (! array_key_exists($relationName, $data)) {
				continue;
			}

			$data[$relationName] = $this->fromPhpRelationValue(
				$relation->getCollection(),
				$data[$relationName],
				$partial
			);
		}

		return $data;
	}

	private function applyToPhp(FieldInterface $field, mixed $value): mixed
	{
		try {
			return $this->registry->resolve($field)->toPhp($value, $field);
		} catch (TypecastException $e) {
			throw new TypecastException($e->getMessage(), $e->getField() ?? $field->getName(), $e);
		}
	}

	private function applyFromPhp(FieldInterface $field, mixed $value): mixed
	{
		try {
			return $this->registry->resolve($field)->fromPhp($value, $field);
		} catch (TypecastException $e) {
			throw new TypecastException($e->getMessage(), $e->getField() ?? $field->getName(), $e);
		}
	}

	private function toPhpRelationValue(CollectionInterface $target, mixed $value): mixed
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
						$result[$action][$index] = $this->toPhp($target, $item);
					}
				}
			}

			return $result;
		}

		if ($this->isAssociativeArray($value)) {
			return $this->toPhp($target, $value);
		}

		foreach ($value as $index => $item) {
			if (is_array($item)) {
				$value[$index] = $this->toPhp($target, $item);
			}
		}

		return $value;
	}

	private function fromPhpRelationValue(CollectionInterface $target, mixed $value, bool $partial): mixed
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
						$result[$action][$index] = $this->fromPhp(
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
			return $this->fromPhp($target, $value, $partial);
		}

		foreach ($value as $index => $item) {
			if (is_array($item)) {
				$value[$index] = $this->fromPhp($target, $item, $partial);
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
