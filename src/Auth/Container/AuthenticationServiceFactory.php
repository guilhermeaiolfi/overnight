<?php

declare(strict_types=1);

namespace ON\Auth\Container;

use ON\Auth\AuthenticationService;
use ON\Auth\AuthenticatorInterface;
use ON\Auth\Storage\StorageInterface;
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
		$authenticator = $this->container->get(AuthenticatorInterface::class);

		return new AuthenticationService($storage, $authenticator);
	}
}
