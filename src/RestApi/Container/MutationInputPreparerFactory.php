<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\Payload\MutationInputPreparer;
use Psr\Container\ContainerInterface;

final class MutationInputPreparerFactory
{
	public function __invoke(ContainerInterface $container): MutationInputPreparer
	{
		return new MutationInputPreparer(
			new MutationInputMerger(),
			$container->get(RestHookDispatcher::class),
		);
	}
}
