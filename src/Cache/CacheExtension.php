<?php

declare(strict_types=1);

namespace ON\Cache;

use ON\Application;
use ON\Cache\Container\CacheClearerRegistryFactory;
use ON\Cache\Container\CacheFactory;
use ON\Cache\Container\FilesystemAdapterFactory;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;

use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;

use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Init\InitContext;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheExtension extends AbstractExtension
{
	public const ID = 'cache';

	protected CacheClearerRegistry $clearers;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		$this->clearers = new CacheClearerRegistry();
	}

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			$config = $event->config;

			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				CacheInterface::class => CacheFactory::class,
				CacheClearerRegistry::class => CacheClearerRegistryFactory::class,
				FilesystemAdapter::class => FilesystemAdapterFactory::class,
			]);

			/*$containerConfig->addAliases([
				AdapterInterface::class => FilesystemAdapter::class,
			]);*/

		});
	}

	public function start(InitContext $context): void
	{
		if (! $this->app->isCli()) {
			return;
		}

		$this->clearers->add(new CacheClearerDefinition(
			name: 'cache',
			label: 'CacheInterface',
			clear: function (ContainerInterface $container): void {
				$container->get(CacheInterface::class)->clear();
			},
			priority: 100,
			description: 'Clears the default framework cache service.'
		));

		$this->clearers->add(new CacheClearerDefinition(
			name: 'app-cache-dir',
			label: 'Application cache directory',
			clear: function (): void {
				CachePathCleaner::clearDirectoryContents($this->app->paths->get('cache')->getAbsolutePath(), fast: true);
			},
			priority: -100,
			description: 'Clears every file and directory inside the application cache path.'
		));

		$this->clearers->add(new CacheClearerDefinition(
			name: 'config',
			label: 'Config',
			clear: function (): void {
				CachePathCleaner::removeFile($this->app->ext('config')->getCachePath());
			},
			priority: 95,
			description: 'Clears cached application configuration.'
		));

		$this->clearers->add(new CacheClearerDefinition(
			name: 'lifecycle',
			label: 'Lifecycle',
			clear: function (): void {
				CachePathCleaner::removeFile($this->app->paths->get('cache')->append('app_lifecycle.php')->getAbsolutePath());
			},
			priority: 94,
			description: 'Clears cached extension lifecycle ordering.'
		));

		$context->emit(new CacheClearersConfigureEvent($this->clearers, $this->app));
	}

	public function getClearerRegistry(): CacheClearerRegistry
	{
		return $this->clearers;
	}

}
