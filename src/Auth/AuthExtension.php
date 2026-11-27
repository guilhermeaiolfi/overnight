<?php

declare(strict_types=1);

namespace ON\Auth;

use ON\Auth\Authenticator\DummyAuthenticator;
use ON\Auth\Container\AuthenticationServiceFactory;
use ON\Auth\Middleware\AuthorizationMiddleware;
use ON\Auth\Middleware\SecurityMiddleware;
use ON\Auth\Storage\SessionStorage;
use ON\Auth\Storage\StorageInterface;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;

class AuthExtension extends AbstractExtension
{
	public const ID = 'auth';

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$containerConfig = $event->containerConfig;
		$containerConfig->addAliases([
			StorageInterface::class => SessionStorage::class,
			AuthenticatorInterface::class => DummyAuthenticator::class,
		]);
		$containerConfig->addFactories([
			AuthenticationServiceInterface::class => AuthenticationServiceFactory::class,
		]);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$event->pipeline->pipe('/', SecurityMiddleware::class, 50);
		$event->pipeline->pipe('/', AuthorizationMiddleware::class, 40);
	}
}
