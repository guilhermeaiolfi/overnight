<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;

interface NodeStateInterface
{
	public function getCollection(): CollectionInterface;

	public function getData(): array;

	public function setData(array $data): void;

	public function sync(array $data, ?array $row = null): void;

	public function getValue(string $column): mixed;

	public function setValue(string $column, mixed $value): void;

	public function resolveValue(mixed $value): mixed;

	public function isValueReady(string $column): bool;

	public function isReady(): bool;

	public function getRow(): ?array;

	public function markReady(array $row): void;

	public function getPrimaryKeyValue(bool $requireReady = true): ?PrimaryKeyValue;

	public function rebindValueRefs(array $values): array;
}
