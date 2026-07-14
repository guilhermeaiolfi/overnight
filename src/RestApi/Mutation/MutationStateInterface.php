<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;

interface MutationStateInterface
{
	public function getCollection(): CollectionInterface;

	public function getData(): array;

	public function setData(array $data): void;

	public function getValue(string $column): mixed;

	public function setValue(string $column, mixed $value): void;

	public function isReady(): bool;

	public function getRow(): ?array;

	public function markReady(array $row): void;

	public function getKey(bool $requireReady = true): ?Key;

	/**
	 * Hook-only bag that is never flushed to the representation.
	 * Null is a valid stored value; use {@see hasMetadata()} to distinguish missing keys.
	 */
	public function setMetadata(string $key, mixed $value): void;

	public function getMetadata(string $key, mixed $default = null): mixed;

	public function hasMetadata(string $key): bool;
}
