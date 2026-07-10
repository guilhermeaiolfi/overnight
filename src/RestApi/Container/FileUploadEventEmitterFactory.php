<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Data\Definition\Registry;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use Psr\Container\ContainerInterface;

final class FileUploadEventEmitterFactory
{
	public function __invoke(ContainerInterface $container): FileUploadEventEmitter
	{
		return new FileUploadEventEmitter(
			$container->get(Registry::class),
			$container->get(RestHookDispatcher::class),
		);
	}
}
