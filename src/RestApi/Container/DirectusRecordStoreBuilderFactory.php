<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Mapper\ConversionGateway;
use ON\ORM\Definition\Registry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Payload\DirectusRecordStoreBuilder;
use ON\RestApi\Repository\ItemRepositoryInterface;
use Psr\Container\ContainerInterface;

final class DirectusRecordStoreBuilderFactory
{
	public function __invoke(ContainerInterface $container): DirectusRecordStoreBuilder
	{
		$items = $container->get(ItemRepositoryInterface::class);
		$handlers = $container->get(HandlerFactory::class);
		$registry = $container->get(Registry::class);

		return new DirectusRecordStoreBuilder(
			$registry,
			$items,
			$handlers,
			$container->get(CycleRecordLoader::class),
			gateway: $container->get(ConversionGateway::class),
		);
	}
}
