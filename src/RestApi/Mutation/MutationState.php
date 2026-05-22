<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;

final class MutationState implements MutationStateInterface
{
	private ?array $row;
	private bool $ready;

	public function __construct(
		private CollectionInterface $collection,
		private array $values = [],
		?array $row = null,
		bool $ready = false
	) {
		$this->row = $row;
		$this->ready = $ready;
	}

	public static function fromRow(CollectionInterface $collection, ?array $row): self
	{
		return new self($collection, $row ?? [], $row, true);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getData(): array
	{
		return $this->values;
	}

	public function setData(array $data): void
	{
		$this->values = $data;
	}

	public function getValue(string $column): mixed
	{
		if (array_key_exists($column, $this->values)) {
			return $this->values[$column];
		}

		if ($this->row !== null && array_key_exists($column, $this->row)) {
			return $this->row[$column];
		}

		return new ValueRef($this, $column);
	}

	public function setValue(string $column, mixed $value): void
	{
		$this->values[$column] = $value;
	}

	public function resolveValue(mixed $value): mixed
	{
		if ($value instanceof ValueRef) {
			return $value->resolve();
		}

		if (is_string($value)) {
			$value = $this->getValue($value);

			return $value instanceof ValueRef ? $value->resolve() : $value;
		}

		return $value;
	}

	public function isValueReady(string $field): bool
	{
		if (array_key_exists($field, $this->values)) {
			$value = $this->values[$field];

			return !$value instanceof ValueRef || $value->isReady();
		}

		return ($this->row !== null && array_key_exists($field, $this->row)) || $this->ready;
	}

	public function isReady(): bool
	{
		return $this->ready;
	}

	public function getRow(): ?array
	{
		return $this->row;
	}

	public function markReady(array $row): void
	{
		$this->row = $row;
		foreach ($row as $field => $value) {
			$this->setValue((string) $field, $value);
		}

		$this->ready = true;
	}
}
