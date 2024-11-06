<?php

declare(strict_types=1);

namespace ON\Auth;

use Laminas\Authentication\Adapter\AdapterInterface;
use Laminas\Authentication\Storage\StorageInterface;
use Psr\Container\ContainerInterface;

class AuthenticationServiceFactory
{
	public function __construct(
		protected ContainerInterface $container
	) {
	}

	public function __invoke()
	{
		$storage = $this->container->get(StorageInterface::class);
		$adapter = $this->container->get(AdapterInterface::class);

		return new AuthenticationService($storage, $adapter);
	}
}
