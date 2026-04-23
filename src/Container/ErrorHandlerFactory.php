<?php

declare(strict_types=1);

namespace ON\Container;

use Laminas\Stratigility\Middleware\ErrorHandler;
use ON\Middleware\ErrorResponseGenerator;
use Psr\Container\ContainerInterface;

class ErrorHandlerFactory
{
	public function __invoke(ContainerInterface $container): ErrorHandler
	{
		$generator = $container->has(ErrorResponseGenerator::class)
			? $container->get(ErrorResponseGenerator::class)
			: null;

		return new ErrorHandler(new ResponseFactory(), $generator);
	}
}
