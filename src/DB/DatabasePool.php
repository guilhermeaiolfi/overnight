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
		$request = $this->request;
		$this->request = null;
		$this->closeInstances($request);
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
		$request = $this->request;
		$this->request = null;
		$instances = $this->instances;
		$this->instances = new SplObjectStorage();

		$this->closeInstances($request, $instances);
	}

	/** @param SplObjectStorage<object, array<string, DatabaseInterface>>|null ...$caches */
	private function closeInstances(?SplObjectStorage ...$caches): void
	{
		$firstError = null;
		foreach ($caches as $instances) {
			if ($instances === null) {
				continue;
			}

			foreach ($instances as $owner) {
				foreach ($instances[$owner] as $database) {
					if ($database instanceof ClosableDatabaseInterface) {
						try {
							$database->close();
						} catch (Throwable $error) {
							$firstError ??= $error;
						}
					}
				}
			}
		}

		if ($firstError !== null) {
			throw $firstError;
		}
	}
}
