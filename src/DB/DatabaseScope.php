<?php

declare(strict_types=1);

namespace ON\DB;

use ON\ScopedResourceInterface;

/** @internal */
final class DatabaseScope implements ScopedResourceInterface
{
	/** @var array<string, DatabaseInterface> */
	private array $contextInstances = [];

	/** @var array<string, DatabaseInterface>|null */
	private ?array $requestInstances = null;

	public function openRequest(): bool
	{
		if ($this->requestInstances !== null) {
			return false;
		}

		$this->requestInstances = [];

		return true;
	}

	public function closeRequest(): void
	{
		$instances = $this->requestInstances ?? [];
		$this->requestInstances = null;
		$this->releaseInstances($instances);
	}

	public function &getInstances(): array
	{
		if ($this->requestInstances !== null) {
			return $this->requestInstances;
		}

		return $this->contextInstances;
	}

	public function release(): void
	{
		$instances = $this->contextInstances;
		$this->contextInstances = [];
		$this->releaseInstances($instances);
	}

	private function releaseInstances(array $instances): void
	{
		foreach ($instances as $database) {
			if ($database instanceof ScopedResourceInterface) {
				$database->release();
			}
		}
	}
}
