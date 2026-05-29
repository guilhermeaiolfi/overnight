<?php

declare(strict_types=1);

namespace ON\Container;

use Mezzio\Helper\BodyParams\BodyParamsMiddleware;
use ON\Middleware\BodyParams\MultipartFormDataStrategy;
use Psr\Container\ContainerInterface;

final class BodyParamsMiddlewareFactory
{
	public function __invoke(ContainerInterface $container): BodyParamsMiddleware
	{
		$middleware = new BodyParamsMiddleware();
		$middleware->addStrategy(new MultipartFormDataStrategy());

		return $middleware;
	}
}
