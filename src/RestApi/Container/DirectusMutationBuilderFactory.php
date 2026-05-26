<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Mapper\ConversionGateway;
use ON\ORM\Definition\Registry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\PayloadNormalizer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use Psr\Container\ContainerInterface;

class DirectusMutationBuilderFactory
{
	public function __invoke(ContainerInterface $container): DirectusMutationBuilder
	{
		$items = $container->get(ItemRepositoryInterface::class);
		$handlers = $container->get(HandlerFactory::class);
		$registry = $container->get(Registry::class);

		return new DirectusMutationBuilder(
			$registry,
			$items,
			new PayloadNormalizer($handlers, $registry),
			$container->get(ConversionGateway::class),
		);
	}
}
