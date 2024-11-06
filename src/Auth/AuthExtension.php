<?php

declare(strict_types=1);

namespace ON\Auth;

use App\Lib\Session\SessionManagerFactory;
use Laminas\Authentication\Storage\Session;
use Laminas\Authentication\Storage\StorageInterface as AuthStorageInterface;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\StorageInterface as CacheStorageInterface;
use Laminas\Session\ManagerInterface;
use ON\Application;
use ON\Config\ContainerConfig;
use ON\Extension\AbstractExtension;

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
				AuthStorageInterface::class => Session::class,
				CacheStorageInterface::class => Filesystem::class,
			]);
			$containerConfig->addFactories([
				AuthenticationServiceInterface::class => AuthenticationServiceFactory::class,
				//ManagerInterface::class => SessionManagerFactory::class,
			]);

		});

		$this->app->ext('pipeline')->when('ready', function () {
		});

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
