<?php

declare(strict_types=1);

namespace ON\DB;

use DI\Container;
use Exception;
use ON\Event\NamedEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class DatabaseManager
{
	protected $instances = [];

	protected $eventDispatcher = null;

	/**
	 * @param Container $container
	 */
	public function __construct(
		protected DatabaseConfig $config,
		protected ContainerInterface $container,
		private ?DatabasePoolInterface $pool = null,
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

	public function getDatabase(?string $name = null)
	{
		if (! isset($name)) {
			if ($this->config->hasDefault()) {
				$name = $this->config->getDefaultName();
			} else {
				throw new Exception("There is no \"default\" DB set and none was given.");
			}
		}

		$database = $this->pool?->getOrCreate($this, $name, fn (): DatabaseInterface => $this->createDatabase($name));

		return $database ?? ($this->instances[$name] ??= $this->createDatabase($name));
	}

	private function createDatabase(string $name): DatabaseInterface
	{
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

		return $database;
	}
}
