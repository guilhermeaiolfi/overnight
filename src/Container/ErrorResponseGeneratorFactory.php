<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Middleware\ErrorResponseGenerator;
use Psr\Container\ContainerInterface;

class ErrorResponseGeneratorFactory
{
	public function __invoke(ContainerInterface $container): ErrorResponseGenerator
	{
		$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

		return new ErrorResponseGenerator($debug);
	}
}
