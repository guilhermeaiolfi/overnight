<?php

declare(strict_types=1);

namespace ON\Http;

use InvalidArgumentException;
use ON\Router\RouteResult;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class InvocationContext
{
	private const RESERVED_NAMED_KEYS = [
		'request',
		'handler',
		'delegate',
	];

	private const RESERVED_TYPED_KEYS = [
		ServerRequestInterface::class,
		RequestHandlerInterface::class,
		RouteResult::class,
	];

	/**
	 * @param array<string, mixed> $named
	 * @param array<string, object> $typed
	 */
	public function __construct(
		private array $named = [],
		private array $typed = []
	) {
	}

	public static function empty(): self
	{
		return new self();
	}

	public static function fromRequest(ServerRequestInterface $request): self
	{
		$context = $request->getAttribute(self::class);

		return $context instanceof self ? $context : self::empty();
	}

	public function with(string $key, mixed $value): self
	{
		$this->assertNamedKeyIsAllowed($key);

		$clone = clone $this;
		$clone->named[$key] = $value;

		return $clone;
	}

	public function withTyped(object $value): self
	{
		$this->assertTypedValueIsAllowed($value);

		$clone = clone $this;
		$clone->typed[$value::class] = $value;

		return $clone;
	}

	public function merge(self $other, bool $overwriteNamed = true): self
	{
		$clone = clone $this;

		foreach ($other->named as $key => $value) {
			if ($overwriteNamed || ! array_key_exists($key, $clone->named)) {
				$clone->named[$key] = $value;
			}
		}

		foreach ($other->typed as $class => $value) {
			$clone->typed[$class] = $value;
		}

		return $clone;
	}

	public function without(string $key): self
	{
		$clone = clone $this;
		unset($clone->named[$key], $clone->typed[$key]);

		return $clone;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->named[$key] ?? $default;
	}

	public function getTyped(string $class): ?object
	{
		return $this->typed[$class] ?? null;
	}

	public function has(string $key): bool
	{
		return array_key_exists($key, $this->named) || array_key_exists($key, $this->typed);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		return array_merge($this->named, $this->typed);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function applyToArgs(array $args): array
	{
		foreach ($this->named as $key => $value) {
			if ($this->isReservedNamedKey($key)) {
				continue;
			}

			if (! array_key_exists($key, $args)) {
				$args[$key] = $value;
			}
		}

		foreach ($this->typed as $class => $value) {
			if ($this->isReservedTypedKey($class)) {
				continue;
			}

			if (! array_key_exists($class, $args)) {
				$args[$class] = $value;
			}
		}

		return $args;
	}

	private function assertNamedKeyIsAllowed(string $key): void
	{
		if ($this->isReservedNamedKey($key)) {
			throw new InvalidArgumentException(sprintf(
				'InvocationContext cannot store reserved named key "%s".',
				$key
			));
		}
	}

	private function assertTypedValueIsAllowed(object $value): void
	{
		foreach (self::RESERVED_TYPED_KEYS as $reservedClass) {
			if ($value instanceof $reservedClass) {
				throw new InvalidArgumentException(sprintf(
					'InvocationContext cannot store reserved typed value "%s".',
					$reservedClass
				));
			}
		}
	}

	private function isReservedNamedKey(string $key): bool
	{
		return in_array($key, self::RESERVED_NAMED_KEYS, true);
	}

	private function isReservedTypedKey(string $class): bool
	{
		return in_array($class, self::RESERVED_TYPED_KEYS, true);
	}
}
