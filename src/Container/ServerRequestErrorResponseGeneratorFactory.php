<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Response\ServerRequestErrorResponseGenerator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;

class ServerRequestErrorResponseGeneratorFactory
{
	public function __invoke(ContainerInterface $container): ServerRequestErrorResponseGenerator
	{
		$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

		$responseFactory = $container->get(ResponseFactoryInterface::class);

		return new ServerRequestErrorResponseGenerator(
			$responseFactory,
			$debug
		);
	}
}
