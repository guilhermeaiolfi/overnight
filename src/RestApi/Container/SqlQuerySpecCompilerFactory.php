<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

final class SqlQuerySpecCompilerFactory
{
	public function __invoke(ContainerInterface $container): SqlQuerySpecCompiler
	{
		$items = $container->get(ItemRepositoryInterface::class);
		$config = $container->get(RestApiConfig::class);

		return new SqlQuerySpecCompiler(
			$items->getDatabase(),
			$config->get('defaultLimit', 100),
			$config->get('maxLimit', 1000)
		);
	}
}
