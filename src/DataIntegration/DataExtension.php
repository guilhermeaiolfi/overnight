<?php

declare(strict_types=1);

namespace ON\DataIntegration;

use ON\Application;
use ON\Cache\CacheClearerDefinition;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\DataIntegration\Command\DefinitionsClearCommand;
use ON\DataIntegration\Command\DefinitionsWarmupCommand;
use ON\DataIntegration\Definition\DefinitionCache;
use ON\DataIntegration\Definition\DefinitionCacheFactory;
use ON\DataIntegration\Definition\DefinitionRegistryProvider;
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\DataIntegration\Mapper\ConversionGatewayFactory;
use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Init\Init;

final class DataExtension extends AbstractExtension
{
	public const ID = 'data';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, [$this, 'onContainerConfigure']);
		$init->on(ContainerReadyEvent::class, [$this, 'onContainerReady']);
		$init->on(DataDefinitionConfigureEvent::class)->done([$this, 'onDataDefinitionConfigureDone']);

		if ($this->app->isCli()) {
			$init->on(ConsoleReadyEvent::class, [$this, 'onConsoleReady']);
		}

		if ($this->app->isCli() && class_exists(CacheClearersConfigureEvent::class)) {
			$init->on(CacheClearersConfigureEvent::class, [$this, 'onCacheClearersConfigure']);
		}
	}

	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			Registry::class => DefinitionRegistryProvider::class,
			DefinitionCache::class => DefinitionCacheFactory::class,
			ConversionGateway::class => ConversionGatewayFactory::class,
		]);
	}

	public function onContainerReady(ContainerReadyEvent $event): void
	{
		$event->container->get(ConversionGateway::class);
	}

	public function onConsoleReady(ConsoleReadyEvent $event): void
	{
		$event->console->addCommand(DefinitionsClearCommand::class);
		$event->console->addCommand(DefinitionsWarmupCommand::class);
	}

	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'data-definitions',
			label: 'ON\Data definitions',
			clear: function (): void {
				(new DefinitionCache($this->getCacheFile()))->clear();
			},
			priority: 92,
			description: 'Clears cached ON\Data definition registry.'
		));
	}

	public function onDataDefinitionConfigureDone(DataDefinitionConfigureEvent $event): void
	{
		(new DefinitionCache($this->getCacheFile()))->write($event->definitions());
	}

	public function getCacheFile(): string
	{
		$cacheFile = $this->options['cache_file'] ?? $this->options['cache_path'] ?? null;

		if (is_string($cacheFile) && trim($cacheFile) !== '') {
			return Path::from($cacheFile, $this->app->paths->get('project'))->getAbsolutePath();
		}

		return $this->app->paths->get('cache')->append('data-definitions.php')->getAbsolutePath();
	}
}
