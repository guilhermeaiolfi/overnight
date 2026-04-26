<?php

declare(strict_types=1);

namespace ON\Auth;

use ON\Application;
use ON\Auth\Authenticator\DummyAuthenticator;
use ON\Auth\Container\AuthenticationServiceFactory;
use ON\Auth\Middleware\AuthorizationMiddleware;
use ON\Auth\Middleware\SecurityMiddleware;
use ON\Auth\Storage\SessionStorage;
use ON\Auth\Storage\StorageInterface;
use ON\Config\Init\ConfigInitEvents;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Middleware\Init\PipelineInitEvents;

class AuthExtension extends AbstractExtension
{
	public const ID = 'auth';
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return [
			'config',
			'container',
			'pipeline',
		];
	}

	public function register(Init $init): void
	{
		$init->on(ConfigInitEvents::CONFIGURE, [$this, 'onConfigConfigure']);
		$init->on(PipelineInitEvents::READY, [$this, 'onPipelineReady']);
	}

	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$containerConfig = $event->config->get(ContainerConfig::class);
		$containerConfig->addAliases([
			StorageInterface::class => SessionStorage::class,
			AuthenticatorInterface::class => DummyAuthenticator::class,
			//AuthorizationServiceInterface::class => AuthorizationService::class,
		]);
		$containerConfig->addFactories([
			AuthenticationServiceInterface::class => AuthenticationServiceFactory::class,
		]);
	}

	public function onPipelineReady(): void
	{
		$this->app->pipe("/", SecurityMiddleware::class, 1);

		$this->app->pipe("/", AuthorizationMiddleware::class, 1);

	}

}
