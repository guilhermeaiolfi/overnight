<?php

declare(strict_types=1);

namespace ON\DB;

use SplObjectStorage;
use Throwable;

/** Holds database instances for one execution and its HTTP requests. */
final class DatabasePool implements DatabasePoolInterface
{
	/** @var SplObjectStorage<object, array<string, DatabaseInterface>> */
	private SplObjectStorage $instances;

	/** @var SplObjectStorage<object, array<string, DatabaseInterface>>|null */
	private ?SplObjectStorage $request = null;

	public function __construct()
	{
		$this->instances = new SplObjectStorage();
	}

	public function openRequest(): bool
	{
		if ($this->request !== null) {
			return false;
		}

		$this->request = new SplObjectStorage();

		return true;
	}

	public function closeRequest(): void
	{
		$requestInstances = $this->request;
		$this->request = null;

		$error = $this->closeInstances($requestInstances);
		if ($error !== null) {
			throw $error;
		}
	}

	public function get(object $owner, string $name): ?DatabaseInterface
	{
		$instances = $this->request ?? $this->instances;

		return $instances[$owner][$name] ?? null;
	}

	public function set(object $owner, string $name, DatabaseInterface $database): void
	{
		$instances = $this->request ?? $this->instances;
		$databases = $instances[$owner] ?? [];
		$databases[$name] = $database;
		$instances[$owner] = $databases;
	}

	public function close(): void
	{
		$requestInstances = $this->request;
		$executionInstances = $this->instances;
		$this->request = null;
		$this->instances = new SplObjectStorage();

		$requestError = $this->closeInstances($requestInstances);
		$executionError = $this->closeInstances($executionInstances);

		$firstError = $requestError ?? $executionError;
		if ($firstError !== null) {
			throw $firstError;
		}
	}

	/** @param SplObjectStorage<object, array<string, DatabaseInterface>>|null $instances */
	private function closeInstances(?SplObjectStorage $instances): ?Throwable
	{
		if ($instances === null) {
			return null;
		}

		$firstError = null;
		foreach ($instances as $owner) {
			foreach ($instances[$owner] as $database) {
				if (! $database instanceof ClosableDatabaseInterface) {
					continue;
				}

				try {
					$database->close();
				} catch (Throwable $error) {
					$firstError ??= $error;
				}
			}
		}

		return $firstError;
	}
}
