<?php

declare(strict_types=1);

namespace ON\DB;

use ArrayObject;
use ON\DB\Cycle\CycleDatabase;
use SplObjectStorage;

final class DatabaseScope
{
	/** @var SplObjectStorage<DatabaseManager, ArrayObject<string, DatabaseInterface>> */
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

	/** @return ArrayObject<string, DatabaseInterface> */
	public function instancesFor(DatabaseManager $manager): ArrayObject
	{
		$scope = $this->request ?? $this;

		return $scope->instances[$manager] ??= new ArrayObject();
	}

	public function close(): void
	{
		$this->closeRequest();
		foreach ($this->instances as $manager) {
			foreach ($this->instances[$manager] as $database) {
				if ($database instanceof CycleDatabase) {
					$database->cleanHeap();
				}
			}
		}
		$this->instances = new SplObjectStorage();
	}
}
