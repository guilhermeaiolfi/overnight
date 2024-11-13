<?php

declare(strict_types=1);

namespace ON\Router;

use ON\RequestStack;
use Psr\Container\ContainerInterface;

class RouterFactory
{
	public function __invoke(ContainerInterface $c)
	{
		$config = $c->has(RouterConfig::class)
		? $c->get(RouterConfig::class)
		: [];

		return new Router(null, null, $config, $c->get(RequestStack::class));
	}
}
