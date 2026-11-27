<?php

declare(strict_types=1);

namespace ON\View\Latte;

use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Init\Attribute\On;
use ON\View\ViewConfig;

class LatteExtension extends AbstractExtension
{
	public const ID = 'latte';

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$viewConfig = $event->config->get(ViewConfig::class);
		$viewConfig->add('latte.extension', 'latte');
	}

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			LatteRenderer::class => LatteRendererFactory::class,
		]);
	}

	#[On(optional: true)]
	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'latte',
			label: 'Latte',
			clear: function (): void {
				$config = $this->app->ext(ConfigExtension::class)->get(ViewConfig::class);
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
