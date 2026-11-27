<?php

declare(strict_types=1);

namespace ON\DB;

use SplObjectStorage;
use Throwable;

final class DatabaseScope
{
	/** @var SplObjectStorage<DatabaseManager, array<string, DatabaseInterface>> */
	private SplObjectStorage $instances;

	private ?self $request = null;

	public function __construct()
	{
		$this->instances = new SplObjectStorage();
	}

	/** A forward shares the request scope already opened by the outer middleware. */
	public function openRequest(): bool
	{
		if ($this->request !== null) {
			return false;
		}

		$this->request = new self();

		return true;
	}

	public function closeRequest(): void
	{
		$request = $this->request;
		$this->request = null;
		$request?->close();
	}

	public function get(DatabaseManager $manager, string $name): ?DatabaseInterface
	{
		$scope = $this->request ?? $this;
		if (! $scope->instances->contains($manager)) {
			return null;
		}

		return $scope->instances[$manager][$name] ?? null;
	}

	public function set(DatabaseManager $manager, string $name, DatabaseInterface $database): void
	{
		$scope = $this->request ?? $this;
		$databases = $scope->instances->contains($manager) ? $scope->instances[$manager] : [];
		$databases[$name] = $database;
		$scope->instances[$manager] = $databases;
	}

	public function close(): void
	{
		$firstError = null;

		try {
			$this->closeRequest();
		} catch (Throwable $error) {
			$firstError = $error;
		}

		$instances = $this->instances;
		$this->instances = new SplObjectStorage();

		foreach ($instances as $manager) {
			foreach ($instances[$manager] as $database) {
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

		if ($firstError !== null) {
			throw $firstError;
		}
	}
}
