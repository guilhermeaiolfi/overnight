<?php

declare(strict_types=1);

namespace ON\DB;

use Laminas\DB\Adapter\Adapter;
use Psr\Container\ContainerInterface;

class LaminasDbDatabase implements DatabaseInterface
{
	protected $adapter;
	protected $parameters;
	protected $container;
	protected string $name;

	public function __construct(string $name, DatabaseConfig $config, ContainerInterface $container)
	{
		$parameters = $config->get("databases.{$name}");
		$this->name = $name;
		$this->parameters = $parameters;
		$this->container = $container;

		$adapter_class = isset($config["adapter_class"]) ? $config["adapter_class"] : Adapter::class;
		$adapter = new $adapter_class($parameters);
		$this->adapter = $adapter;
	}

	protected function getAdapterClass()
	{
		$config = $this->parameters;

		return isset($config["adapter_class"]) ? $config["adapter_class"] : Adapter::class;
	}

	public function getConnection()
	{
		return $this->adapter;
	}

	public function getResource()
	{
		return $this->adapter->getDriver()->getConnection()->getResource();
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
