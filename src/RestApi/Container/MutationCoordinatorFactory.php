<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Data\DataRuntime;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Mutation\DirectusMutationBinder;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Mutation\SessionFactory;
use ON\RestApi\Repository\ItemRepositoryInterface;
use Psr\Container\ContainerInterface;

final class MutationCoordinatorFactory
{
	public function __invoke(ContainerInterface $container): MutationCoordinator
	{
		$runtime = $container->get(DataRuntime::class);
		$items = $container->get(ItemRepositoryInterface::class);

		$sessions = new SessionFactory($runtime);

		return new MutationCoordinator(
			$sessions,
			new DirectusMutationBinder($items, $sessions),
			new DirectusPayloadParser(),
			$items,
			$container->get(RestHookDispatcher::class),
			$runtime,
		);
	}
}
