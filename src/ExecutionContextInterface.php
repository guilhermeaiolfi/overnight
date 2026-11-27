<?php

declare(strict_types=1);

namespace ON;

interface ExecutionContextInterface
{
	/**
	 * Return an ID unique among active contexts or null outside a managed context.
	 */
	public function getId(): ?string;

	/**
	 * Run the callback when the current context exits, including on failure.
	 */
	public function onExit(callable $callback): void;
}
