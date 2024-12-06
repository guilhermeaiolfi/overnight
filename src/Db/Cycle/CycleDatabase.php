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

class CycleDatabase implements DatabaseInterface
{
	protected $dbal = null;
	protected $orm = null;
	protected string $name;

	public function __construct($name, $parameters, $container)
	{
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
