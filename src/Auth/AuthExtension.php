<?php

declare(strict_types=1);

namespace ON\Auth;

use Laminas\Session\ManagerInterface;
use ON\Application;
use ON\Auth\Authenticator\DummyAuthenticator;
use ON\Auth\Container\AuthenticationServiceFactory;
use ON\Auth\Middleware\AuthorizationMiddleware;
use ON\Auth\Middleware\SecurityMiddleware;
use ON\Auth\Storage\LaminasSessionStorage;
use ON\Auth\Storage\StorageInterface;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Session\Container\SessionManagerFactory;

class AuthExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		$app->registerExtension('auth', $extension);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function requires(): array
	{
		return [
			'config',
			'container',
		];
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
			$config = $this->app->config;

			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addAliases([
				StorageInterface::class => LaminasSessionStorage::class,
				AuthenticatorInterface::class => DummyAuthenticator::class,
				AuthorizationServiceInterface::class => AuthorizationService::class,
			]);
			$containerConfig->addFactories([
				AuthenticationServiceInterface::class => AuthenticationServiceFactory::class,
				ManagerInterface::class => SessionManagerFactory::class,
			]);
		});

		$this->app->ext('pipeline')->when('ready', [$this, "onPipelineReady"]);

		$this->setState('ready');
	}

	public function onPipelineReady(): void
	{
		$this->app->pipe("/", SecurityMiddleware::class, 1);

		$this->app->pipe("/", AuthorizationMiddleware::class, 1);

		$this->setState('ready');
	}

	public function setup(): void
	{

	}
}
