<?php

declare(strict_types=1);

namespace ON\DB\Cycle;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use ON\CMS\Compiler\CycleCompiler;
use ON\CMS\Definition\Registry;
use ON\DB\DatabaseInterface;
use Psr\Container\ContainerInterface;

class CycleDatabase implements DatabaseInterface
{
	protected $dbal = null;
	protected $orm = null;

	public function __construct(
		protected string $name,
		protected DatabaseConfig $config, 
		protected ContainerInterface $container
	)
	{
		$parameters = $config->get("databases.{$name}");
		$this->name = $name;
		$cycle_config = new DatabaseConfig($parameters["options"]);

		$this->dbal = new DatabaseManager($cycle_config);

		$collections = Registry::getCollections();
		$compiler = new CycleCompiler($collections);
		$schema = $compiler->compile();
		$this->orm = new ORM(new Factory($this->dbal), $schema);
	}

	public function getConnection()
	{
		return $this->dbal;
	}

	public function getResource()
	{
		return $this->orm;
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
