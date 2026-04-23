<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\ORM\Definition\Registry;
use ON\RestApi\Resolver\CycleRestResolver;
use ON\RestApi\Resolver\RestResolverInterface;
use ON\RestApi\Resolver\SqlFilterParser;
use ON\RestApi\Resolver\SqlRestResolver;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

class RestResolverFactory
{
	public function __invoke(ContainerInterface $container): ?RestResolverInterface
	{
		$config = $container->get(RestApiConfig::class);
		$resolverType = $config->get('resolver', 'auto');

		if ($resolverType !== 'auto' && $resolverType !== 'sql' && $resolverType !== 'cycle') {
			return $container->get($resolverType);
		}

		$registry = $container->get(Registry::class);

		if (!$container->has(DatabaseManager::class)) {
			return null;
		}

		$manager = $container->get(DatabaseManager::class);
		$database = $manager->getDatabase();

		if ($database === null) {
			return null;
		}

		$defaultLimit = $config->get('defaultLimit', 100);
		$maxLimit = $config->get('maxLimit', 1000);

		if ($resolverType === 'cycle') {
			$orm = $database->getResource();
			return new CycleRestResolver($orm, $registry, $defaultLimit, $maxLimit);
		}

		if ($resolverType === 'sql') {
			return new SqlRestResolver(
				$registry,
				$database,
				new SqlFilterParser(),
				$defaultLimit,
				$maxLimit
			);
		}

		if ($database instanceof CycleDatabase) {
			$orm = $database->getResource();
			return new CycleRestResolver($orm, $registry, $defaultLimit, $maxLimit);
		}

		return new SqlRestResolver(
			$registry,
			$database,
			new SqlFilterParser(),
			$defaultLimit,
			$maxLimit
		);
	}
}
