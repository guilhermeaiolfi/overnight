<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\ImageManager as InterventionImageManager;
use ON\Application;
use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Image\Container\ImageManagerFactory;
use ON\Image\Container\InterventionImageManagerFactory;
use ON\Init\Init;
use ON\Init\InitContext;
use ON\Router\RouterConfig;

class ImageExtension extends AbstractExtension
{
	public const ID = 'image';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, [$this, 'onConfigConfigure']);
		$init->on(ContainerConfigureEvent::class, [$this, 'onContainerConfigure']);
		if ($this->app->isCli() && class_exists(CacheClearersConfigureEvent::class)) {
			$init->on(CacheClearersConfigureEvent::class, [$this, 'onCacheClearersConfigure']);
		}
	}

	public function start(InitContext $context): void
	{
	}

	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$image_cfg = $event->config->get(ImageConfig::class);
		$router_cfg = $event->config->get(RouterConfig::class);
		$router_cfg->addRoute(
			'/' . $image_cfg->getPublicImagesUri() . '/{uri:\S+}',
			"ON\Image\ImageManager::process",
			['GET'],
			"imagemanager",
		);
	}

	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactory(ImageManager::class, ImageManagerFactory::class);
		$event->containerConfig->addFactory(InterventionImageManager::class, InterventionImageManagerFactory::class);
	}

	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'image',
			label: 'Image',
			clear: function (): void {
				$config = $this->app->config->get(ImageConfig::class);
				$cachePath = Path::from($config->getPublicImagesUri(), $this->app->paths->get('public'))
					->getAbsolutePath();

				CachePathCleaner::clearDirectoryContents($cachePath, fast: true);
			},
			priority: 40,
			description: 'Clears generated filesystem image cache files.'
		));
	}
}
