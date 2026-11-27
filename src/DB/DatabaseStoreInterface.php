<?php

declare(strict_types=1);

namespace ON\DB;

interface DatabaseStoreInterface
{
	public function get(string $name): ?DatabaseInterface;

	public function set(string $name, DatabaseInterface $database): void;

	public function close(): void;
}
