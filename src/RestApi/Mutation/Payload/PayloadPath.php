<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

/**
 * Dot-path into a Directus mutation payload for errors and nested events.
 *
 * @param list<string|int> $segments
 */
final readonly class PayloadPath
{
	/**
	 * @param list<string|int> $segments
	 */
	public function __construct(
		public array $segments = [],
	) {
	}

	public static function root(): self
	{
		return new self([]);
	}

	public function append(string|int $segment): self
	{
		$segments = $this->segments;
		$segments[] = $segment;

		return new self($segments);
	}

	/**
	 * @return list<string|int>
	 */
	public function toArray(): array
	{
		return $this->segments;
	}

	public function toString(): string
	{
		return implode('.', array_map(static fn (string|int $segment): string => (string) $segment, $this->segments));
	}

	public function isRoot(): bool
	{
		return $this->segments === [];
	}
}
