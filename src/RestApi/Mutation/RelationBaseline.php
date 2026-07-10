<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Key;

/**
 * Current represented relation membership for reconciliation and scope checks.
 *
 * Keys are normalized via {@see Key} (canonical field order). Duplicate
 * represented identities are collapsed when the baseline is built.
 */
final readonly class RelationBaseline
{
	/**
	 * @param array<string, Key> $keysByHash Key::getHash() => Key
	 * @param array<string, object> $itemsByNormalizedKey Key::getHash() => representation
	 */
	public function __construct(
		public array $keysByHash,
		public array $itemsByNormalizedKey,
	) {
	}

	public static function empty(): self
	{
		return new self([], []);
	}

	public function contains(Key $key): bool
	{
		return isset($this->keysByHash[$key->getHash()]);
	}

	public function find(Key $key): ?object
	{
		return $this->itemsByNormalizedKey[$key->getHash()] ?? null;
	}

	/**
	 * @return list<object>
	 */
	public function items(): array
	{
		return array_values($this->itemsByNormalizedKey);
	}

	public function isEmpty(): bool
	{
		return $this->itemsByNormalizedKey === [];
	}
}
