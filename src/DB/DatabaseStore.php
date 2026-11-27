<?php

declare(strict_types=1);

namespace ON\DB;

use Throwable;

final class DatabaseStore implements DatabaseStoreInterface
{
	/** @var array<string, DatabaseInterface> */
	private array $instances = [];

	private bool $scoped = false;

	public function get(string $name): ?DatabaseInterface
	{
		return $this->instances[$name] ?? null;
	}

	public function set(string $name, DatabaseInterface $database): void
	{
		$this->instances[$name] = $database;
	}

	public function scoped(callable $callback): mixed
	{
		if ($this->scoped) {
			return $callback();
		}

		$previous = $this->instances;
		$this->instances = [];
		$this->scoped = true;

		try {
			return $callback();
		} finally {
			$scopedInstances = $this->instances;
			$this->instances = $previous;
			$this->scoped = false;
			$this->closeInstances($scopedInstances);
		}
	}

	public function close(): void
	{
		$instances = $this->instances;
		$this->instances = [];

		$this->closeInstances($instances);
	}

	/** @param array<string, DatabaseInterface> $instances */
	private function closeInstances(array $instances): void
	{
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
