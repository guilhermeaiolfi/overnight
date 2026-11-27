<?php

declare(strict_types=1);

namespace ON\DB\Cycle;

use Cycle\Database\Config\DatabaseConfig as CycleDatabaseConfig;
use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use Cycle\Database\Driver\DriverInterface;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\Schema\Registry;
use ON\DB\ClosableDatabaseInterface;
use ON\DB\DatabaseConfig;
use ReflectionClass;
use RuntimeException;
use function sprintf;

class CycleDatabase implements ClosableDatabaseInterface
{
	protected $entityManager = null;
	protected $dbal = null;
	protected $orm = null;
	protected ?Registry $registry = null;

	public function __construct(
		protected string $name,
		protected DatabaseConfig $config,
		protected ?Schema $schema = null
	) {
		$parameters = $config->get("databases.{$name}");

		$cycle_config = new CycleDatabaseConfig($parameters["options"]);

		$this->dbal = new CycleDatabaseManager($cycle_config);

		if (isset($schema)) {
			$this->setSchema($schema);
		}
	}

	public function setSchema(Schema $schema): void
	{
		$this->orm = new ORM(new Factory($this->dbal), $schema);
		$this->entityManager = new EntityManager($this->orm);
	}

	public function setRegistry(Registry $registry): void
	{
		$this->registry = $registry;
	}

	public function getRegistry(): Registry
	{
		if ($this->registry === null) {
			throw new RuntimeException(
				sprintf('CycleDatabase "%s" registry has not been initialized. Call setRegistry() first.', $this->name)
			);
		}

		return $this->registry;
	}

	public function getConnection(): mixed
	{
		return $this->dbal;
	}

	public function getResource(): mixed
	{
		return $this->orm;
	}

	public function getEntityManager(): EntityManager
	{
		if ($this->entityManager === null) {
			throw new RuntimeException(
				sprintf('CycleDatabase "%s" entity manager has not been initialized. Call setSchema() first.', $this->name)
			);
		}

		return $this->entityManager;
	}

	public function cleanHeap(): void
	{
		$this->orm?->getHeap()->clean();
	}

	public function close(): void
	{
		$this->cleanHeap();

		if (! $this->dbal instanceof CycleDatabaseManager) {
			return;
		}

		foreach ($this->getInitializedDrivers($this->dbal) as $driver) {
			if ($driver->isConnected()) {
				$driver->disconnect();
			}
		}
	}

	/** @return iterable<DriverInterface> */
	private function getInitializedDrivers(CycleDatabaseManager $dbal): iterable
	{
		$reflection = new ReflectionClass($dbal);
		if ($reflection->hasProperty('drivers')) {
			$drivers = $reflection->getProperty('drivers')->getValue($dbal);
			if (is_array($drivers)) {
				return $drivers;
			}
		}

		return $dbal->getDrivers();
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}
}
