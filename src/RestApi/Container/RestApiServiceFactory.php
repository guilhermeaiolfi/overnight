<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\ORM\Definition\Registry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Query\QueryPlanner;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiService;
use ON\RestApi\Serialize\CollectionSerializer;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiServiceFactory
{
	public function __invoke(ContainerInterface $container): RestApiService
	{
		$sqlDataSource = $container->get(SqlDataSource::class);
		$config = $container->get(RestApiConfig::class);
		$querySpecCompiler = new SqlQuerySpecCompiler(
			$sqlDataSource->getDatabase(),
			$config->get('defaultLimit', 100),
			$config->get('maxLimit', 1000)
		);
		$handlers = new HandlerFactory(HandlerRegistry::defaults(), $sqlDataSource, $querySpecCompiler);
		$queryPlanner = new QueryPlanner($sqlDataSource, $handlers, $querySpecCompiler);

		return new RestApiService(
			$container->get(Registry::class),
			$sqlDataSource,
			$queryPlanner,
			$container->get(EventDispatcherInterface::class),
			$handlers,
			new CollectionSerializer(),
		);
	}
}
