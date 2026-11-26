<?php

declare(strict_types=1);

namespace ON\Router\Container;

use ON\Router\Router;
use ON\Router\RouterConfig;
use Psr\Container\ContainerInterface;

class RouterFactory
{
	public function __invoke(ContainerInterface $c): Router
	{
		$config = $c->has(RouterConfig::class)
			? $c->get(RouterConfig::class)
			: new RouterConfig();

		return new Router($config);
	}
}
