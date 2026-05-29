<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\ORM\Definition\Registry;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class FileUploadEventEmitterFactory
{
	public function __invoke(ContainerInterface $container): FileUploadEventEmitter
	{
		return new FileUploadEventEmitter(
			$container->get(Registry::class),
			$container->get(EventDispatcherInterface::class),
		);
	}
}
