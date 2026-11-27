<?php

declare(strict_types=1);

namespace ON\DB;

use DI\Container;
use Exception;
use ON\DB\Cycle\CycleDatabase;
use ON\Event\NamedEvent;
use ON\ExecutionContextInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class DatabaseManager
{
	protected $instances = [];

	/** @var array<array-key, array<string, DatabaseInterface>> */
	private array $requestInstances = [];

	/** @var array<array-key, array<string, DatabaseInterface>> */
	private array $contextInstances = [];

	protected $eventDispatcher = null;

	/**
	 * @param Container $container
	 */
	public function __construct(
		protected DatabaseConfig $config,
		protected ContainerInterface $container,
		private ?ExecutionContextInterface $executionContext = null,
	) {
	}

	public function setEventDispatcher(EventDispatcherInterface $dispatcher)
	{
		$this->eventDispatcher = $dispatcher;
	}

	public function getDatabaseConnection($name = null)
	{
		$database = $this->getDatabase($name);
		if ($database) {
			return $database->getConnection();
		}

		return null;
	}

	public function getDatabaseResource($name = null)
	{
		$database = $this->getDatabase($name);
		if ($database) {
			return $database->getResource();
		}

		return null;
	}

	/**
	 * Returns true when the caller opens the scope and is responsible for closing it.
	 * A forwarded request shares the scope that is already open.
	 *
	 * @internal
	 */
	public function openRequestScope(string $contextId): bool
	{
		if (isset($this->requestInstances[$contextId])) {
			return false;
		}

		$this->requestInstances[$contextId] = [];

		return true;
	}

	/** @internal */
	public function closeRequestScope(string $contextId): void
	{
		$this->clearScope($this->requestInstances, $contextId);
	}

	public function getDatabase(?string $name = null)
	{
		if (! isset($name)) {
			if ($this->config->hasDefault()) {
				$name = $this->config->getDefaultName();
			} else {
				throw new Exception("There is no \"default\" DB set and none was given.");
			}
		}

		$instances = &$this->getInstances();
		if (isset($instances[$name])) {
			return $instances[$name];
		}

		$db_config = $this->config->getDatabase($name);
		$database_class = null;
		if (isset($db_config["class"])) {
			$database_class = $db_config["class"];
		} else {
			throw new Exception("There is no \"class\" defined for " . $name . " database configuration");
		}

		$database = $this->container->make($database_class, [
			"name" => $name,
		]);

		if ($this->eventDispatcher) {
			$event = new NamedEvent("core.db.manager.create", $database);
			$this->eventDispatcher->dispatch($event);
		}

		return $instances[$name] = $database;
	}

	private function &getInstances(): array
	{
		$id = $this->executionContext?->getId();
		if ($id === null) {
			return $this->instances;
		}

		if (isset($this->requestInstances[$id])) {
			return $this->requestInstances[$id];
		}

		if (! isset($this->contextInstances[$id])) {
			$this->executionContext->onExit(function () use ($id): void {
				$this->clearScope($this->contextInstances, $id);
			});
			$this->contextInstances[$id] = [];
		}

		return $this->contextInstances[$id];
	}

	private function clearScope(array &$scopes, string $id): void
	{
		$instances = $scopes[$id];
		unset($scopes[$id]);
		foreach ($instances as $database) {
			if ($database instanceof CycleDatabase) {
				$database->cleanHeap();
			}
		}
	}
}
