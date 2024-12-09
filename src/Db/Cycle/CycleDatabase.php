<?php

declare(strict_types=1);

namespace ON\DB\Cycle;

use Cycle\Database\Config\DatabaseConfig as CycleDatabaseConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use ON\DB\DatabaseConfig;
use ON\CMS\Compiler\CycleCompiler;
use ON\CMS\Definition\Registry;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseInterface;

use Cycle\ORM\Schema;

class CycleDatabase implements DatabaseInterface
{
	protected $entityManager = null;
	protected $dbal = null;
	protected $orm = null;

	public function __construct(
		protected string $name,
		protected DatabaseConfig $config,
		protected Schema $schema
	)
	{
		$parameters = $config->get("databases.{$name}");

		$cycle_config = new CycleDatabaseConfig($parameters["options"]);

		$this->dbal = new CycleDatabaseConfig($cycle_config);

		$this->orm = new ORM(new Factory($this->dbal), $schema);

		$this->entityManager = new EntityManager($this->orm);
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
		return $this->entityManager;
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
