<?php

declare(strict_types=1);

namespace ON\DB;

use Throwable;

final class DatabaseStore implements DatabaseStoreInterface
{
	/** @var array<string, DatabaseInterface> */
	private array $instances = [];

	public function get(string $name): ?DatabaseInterface
	{
		return $this->instances[$name] ?? null;
	}

	public function set(string $name, DatabaseInterface $database): void
	{
		$this->instances[$name] = $database;
	}

	public function close(): void
	{
		$instances = $this->instances;
		$this->instances = [];

		$firstError = null;
		foreach ($instances as $database) {
			if (! $database instanceof ClosableDatabaseInterface) {
				continue;
			}

			try {
				$database->close();
			} catch (Throwable $error) {
				$firstError ??= $error;
			}
		}

		if ($firstError !== null) {
			throw $firstError;
		}
	}
}
