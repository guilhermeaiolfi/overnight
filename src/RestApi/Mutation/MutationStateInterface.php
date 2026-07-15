<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;

/**
 * Hook-facing mutation state for a single item (root or nested).
 *
 * Primary-key fields for existing items live in identity ({@see getKey()}), not in
 * {@see getData()}. Never infer create vs update from {@code isset(getData()['id'])}.
 * Use {@see isCreate()} instead.
 */
interface MutationStateInterface
{
	public function getCollection(): CollectionInterface;

	/**
	 * Pending scalar overlay only. For existing items, primary-key fields are omitted
	 * here even when the client sent them — they are held as identity.
	 *
	 * @return array<string, mixed>
	 */
	public function getData(): array;

	public function setData(array $data): void;

	public function getValue(string $column): mixed;

	public function setValue(string $column, mixed $value): void;

	/**
	 * Whether this item is being created (vs updated / membership-linked / deleted).
	 * Nested scalar identity refs and existing related objects are not creates.
	 */
	public function isCreate(): bool;

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
