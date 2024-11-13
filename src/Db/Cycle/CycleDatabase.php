<?php

declare(strict_types=1);

namespace ON\Db\Cycle;

use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use ON\Db\DatabaseInterface;
use Spiral\Database\DatabaseManager;

class CycleDatabase implements DatabaseInterface
{
	protected $dbal = null;
	protected $orm = null;
	protected string $name;

	public function __construct($name, $parameters, $container)
	{
		$this->name = $name;
		$config = $container->get('config');
		$cycle_config = new DatabaseConfig($parameters);
		$this->dbal = new DatabaseManager($cycle_config);

		$this->orm = new ORM(new Factory($this->dbal));
		$this->orm = $this->orm->withSchema(new Schema($config->get('cycle.schema')));
	}

	public function getConnection()
	{
		return $this->orm;
	}

	public function getResource()
	{
		return $this->dbal;
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
