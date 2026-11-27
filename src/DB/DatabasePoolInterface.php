<?php

declare(strict_types=1);

namespace ON\DB;

interface DatabasePoolInterface
{
	/** Look up a named database for the given manager's key. */
	public function get(object $owner, string $name): ?DatabaseInterface;

	public function set(object $owner, string $name, DatabaseInterface $database): void;

	/** Return true when the caller opens the request and owns its cleanup. */
	public function openRequest(): bool;

	public function closeRequest(): void;
}
