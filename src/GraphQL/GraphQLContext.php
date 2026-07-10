<?php

declare(strict_types=1);

namespace ON\GraphQL;

use Psr\Http\Message\ServerRequestInterface;

class GraphQLContext
{
	public function __construct(
		protected ServerRequestInterface $request,
		protected array $attributes = []
	) {
	}

	public function getRequest(): ServerRequestInterface
	{
		return $this->request;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->attributes[$key] ?? $this->request->getAttribute($key, $default);
	}

	public function set(string $key, mixed $value): void
	{
		$this->attributes[$key] = $value;
	}

	public function has(string $key): bool
	{
		return isset($this->attributes[$key]) || $this->request->getAttribute($key) !== null;
	}
}
