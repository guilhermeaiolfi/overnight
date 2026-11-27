<?php

declare(strict_types=1);

namespace ON\DB;

use DI\Container;
use Exception;
use ON\DB\Cycle\CycleDatabase;
use ON\Event\NamedEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine;

class DatabaseManager
{
	protected $instances = [];

	/** @var array<int, array<string, DatabaseInterface>> */
	private array $requestInstances = [];

	/** @var array<int, array<string, DatabaseInterface>> */
	private array $coroutineInstances = [];

	protected $eventDispatcher = null;

	/**
	 * @param Container $container
	 */
	public function __construct(
		protected DatabaseConfig $config,
		protected ContainerInterface $container,
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
	 * The outermost HTTP dispatch in a coroutine owns the database scope.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function usingRequest(callable $callback): mixed
	{
		$id = $this->getCoroutineId();
		if ($id <= 0 || isset($this->requestInstances[$id])) {
			return $callback();
		}

		$this->requestInstances[$id] = [];

		try {
			return $callback();
		} finally {
			$this->clearScope($this->requestInstances, $id);
		}
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

	private function getCoroutineId(): int
	{
		return class_exists(Coroutine::class, false) ? Coroutine::getCid() : -1;
	}

	private function &getInstances(): array
	{
		$id = $this->getCoroutineId();
		if ($id <= 0) {
			return $this->instances;
		}

		if (isset($this->requestInstances[$id])) {
			return $this->requestInstances[$id];
		}

		if (! isset($this->coroutineInstances[$id])) {
			Coroutine::defer(function () use ($id): void {
				$this->clearScope($this->coroutineInstances, $id);
			});
			$this->coroutineInstances[$id] = [];
		}

		return $this->coroutineInstances[$id];
	}

	private function clearScope(array &$scopes, int $id): void
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
