<?php

declare(strict_types=1);

namespace ON\Router\Container;

use ON\RequestStackInterface;
use ON\Router\Exception\RuntimeException;
use ON\Router\RouterInterface;
use ON\Router\UrlHelper;
use Psr\Container\ContainerInterface;

class UrlHelperFactory
{
	public function __invoke(ContainerInterface $container): UrlHelper
	{
		$request = $container->get(RequestStackInterface::class)->getCurrentRequest();

		if ($request === null) {
			throw new RuntimeException('UrlHelper requires a current request in the request stack.');
		}

		return new UrlHelper(
			$container->get(RouterInterface::class),
			$request
		);
	}
}
