<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\ORM\Definition\Registry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\MutationSpecUnserializer;
use ON\RestApi\Payload\PayloadNormalizer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

class DirectusMutationBuilderFactory
{
	public function __invoke(ContainerInterface $container): DirectusMutationBuilder
	{
		$items = $container->get(ItemRepositoryInterface::class);
		$config = $container->get(RestApiConfig::class);
		$querySpecCompiler = new SqlQuerySpecCompiler(
			$items->getDatabase(),
			$config->get('defaultLimit', 100),
			$config->get('maxLimit', 1000)
		);
		$handlers = new HandlerFactory(HandlerRegistry::defaults(), $items, $querySpecCompiler);
		$registry = $container->get(Registry::class);

		return new DirectusMutationBuilder(
			$registry,
			$items,
			new PayloadNormalizer($handlers, $registry),
			new MutationSpecUnserializer($registry),
		);
	}
}
