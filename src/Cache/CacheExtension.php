<?php

declare(strict_types=1);

namespace ON\Cache;

use Exception;
use ON\Application;
use ON\Cache\Container\CacheFactory;
use ON\Cache\Container\FilesystemAdapterFactory;
use ON\Container\ContainerConfig;
use ON\Container\Init\ContainerInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheExtension extends AbstractExtension
{
	public const ID = 'cache';
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::SETUP, function (): void {
			$config = $this->app->config;

			if (! isset($config)) {
				throw new Exception("Cache Extension needs the config extension");
			}
			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				CacheInterface::class => CacheFactory::class,
				FilesystemAdapter::class => FilesystemAdapterFactory::class,
			]);

			/*$containerConfig->addAliases([
				AdapterInterface::class => FilesystemAdapter::class,
			]);*/

		});
	}

}
