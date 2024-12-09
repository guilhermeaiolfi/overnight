<?php

declare(strict_types=1);

namespace ON\DB\Cycle;

use ON\DB\DatabaseConfig;
use ON\CMS\Compiler\CycleCompiler;
use ON\CMS\Definition\Registry;
use Psr\Container\ContainerInterface;

class CycleDatabaseFactory
{

	public function __invoke(ContainerInterface $container, string $name): CycleDatabase
	{
		if (! $container->has(DatabaseConfig::class)) {
			throw new Exception("There is no DatabaseConfig set");
		}
		$config = $container->get(DatabaseConfig::class);

		$collections = Registry::getCollections();
		$compiler = new CycleCompiler($collections);
		$schema = $compiler->compile();

		$manager = new CycleDatabase($name, $config, $schema);

		return $manager;
	}
}
