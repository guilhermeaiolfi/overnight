<?php

declare(strict_types=1);

namespace ON\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestContext
{
	private array $values = [];

	public function __construct(
		public readonly ?ServerRequestInterface $parentRequest = null,
		public readonly ?self $parentContext = null
	) {
	}

	public function set(string $key, mixed $value): void
	{
		$this->values[$key] = $value;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->values[$key] ?? $default;
	}

	public function has(string $key): bool
	{
		return array_key_exists($key, $this->values);
	}

	public function all(): array
	{
		return $this->values;
	}
}
