<?php

declare(strict_types=1);

namespace ON\DB;

interface DatabasePoolInterface
{
	/**
	 * Reuse a named database within the active request or execution.
	 * Return null without calling the factory outside a managed execution.
	 *
	 * @param callable(): DatabaseInterface $create
	 */
	public function getOrCreate(DatabaseManager $manager, string $name, callable $create): ?DatabaseInterface;

	/** Return true when the caller opens the request and owns its cleanup. */
	public function openRequest(): bool;

	public function closeRequest(): void;
}
