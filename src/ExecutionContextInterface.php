<?php

declare(strict_types=1);

namespace ON;

interface ExecutionContextInterface
{
	public function isActive(): bool;

	/**
	 * Reuse one resource per owner in the current context and release it on exit.
	 * Return null outside a managed context.
	 *
	 * @template T of ScopedResourceInterface
	 * @param callable(): T $create
	 * @return T|null
	 */
	public function getScopedResource(object $owner, callable $create): ?ScopedResourceInterface;
}
