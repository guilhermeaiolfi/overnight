<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Repository\ItemRepositoryInterface;
use Psr\Container\ContainerInterface;

final class HandlerFactoryFactory
{
	public function __invoke(ContainerInterface $container): HandlerFactory
	{
		return new HandlerFactory(
			HandlerRegistry::defaults(),
			$container->get(ItemRepositoryInterface::class),
		);
	}
}
