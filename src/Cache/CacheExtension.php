<?php

declare(strict_types=1);

namespace ON\Cache;

use ON\Application;
use ON\Cache\Container\CacheClearerRegistryFactory;
use ON\Cache\Container\CacheFactory;
use ON\Cache\Container\FilesystemAdapterFactory;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Console\Command\ClearCacheCommand;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Init\InitException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheExtension extends AbstractExtension
{
	public const ID = 'cache';

	protected CacheClearerRegistry $clearers;
	private bool $clearersConfigured = false;

	public function __construct(
		Application $app,
		array $options = []
	) {
		parent::__construct($app, $options);
		$this->clearers = new CacheClearerRegistry();
	}

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			CacheInterface::class => CacheFactory::class,
			CacheClearerRegistry::class => CacheClearerRegistryFactory::class,
			FilesystemAdapter::class => FilesystemAdapterFactory::class,
		]);
	}

	#[On(optional: true)]
	public function onConsoleReady(ConsoleReadyEvent $event): void
	{
		$event->console->addCommand(ClearCacheCommand::class);
	}

	public function getClearerRegistry(): CacheClearerRegistry
	{
		if ($this->clearersConfigured) {
			return $this->clearers;
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
			name: 'lifecycle',
			label: 'Lifecycle',
			clear: function (): void {
				CachePathCleaner::removeFile($this->app->paths->get('cache')->append('app_lifecycle.php')->getAbsolutePath());
			},
			priority: 94,
			description: 'Clears cached extension lifecycle ordering.'
		));

		$this->clearersConfigured = true;

		try {
			$this->app->init()->emit(new CacheClearersConfigureEvent($this->clearers, $this->app));
		} catch (InitException $error) {
			$this->clearers = new CacheClearerRegistry();
			$this->clearersConfigured = false;

			throw $error;
		}

		return $this->clearers;
	}
}
