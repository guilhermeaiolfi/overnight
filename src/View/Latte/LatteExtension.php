<?php

declare(strict_types=1);

namespace ON\View\Latte;

use ON\Application;
use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Container\ContainerConfig;

use ON\Config\Init\Event\ConfigConfigureEvent;

use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Init\Init;
use ON\View\ViewConfig;

class LatteExtension extends AbstractExtension
{
	public const ID = 'latte';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			$containerConfig = $event->config->get(ContainerConfig::class);
			$viewConfig = $event->config->get(ViewConfig::class);

			$viewConfig->add('latte.extension', 'latte');

			$containerConfig->addFactories([
				LatteRenderer::class => LatteRendererFactory::class,
			]);

		});

		if ($this->app->isCli() && class_exists(CacheClearersConfigureEvent::class)) {
			$init->on(CacheClearersConfigureEvent::class, [$this, 'onCacheClearersConfigure']);
		}
	}

	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'latte',
			label: 'Latte',
			clear: function (): void {
				$config = $this->app->config->get(ViewConfig::class);
				$tempDirectory = $config->get('latte.tempDirectory', null);
				if (is_string($tempDirectory) && $tempDirectory !== '') {
					$tempDirectory = Path::from($tempDirectory, $this->app->paths->get('project'))
						->getAbsolutePath();
				}

				CachePathCleaner::clearDirectoryContents(is_string($tempDirectory) ? $tempDirectory : null);
			},
			priority: 50,
			description: 'Clears compiled Latte templates.'
		));
	}
}
