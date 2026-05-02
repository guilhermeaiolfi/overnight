<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\ImageManager as InterventionImageManager;
use ON\Application;
use ON\Container\ContainerConfig;

use ON\Config\Init\Event\ConfigConfigureEvent;

use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Image\Container\ImageManagerFactory;
use ON\Image\Container\InterventionImageManagerFactory;
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
	}

	public function start(\ON\Init\InitContext $context): void
	{
	}

	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$containerConfig = $event->config->get(ContainerConfig::class);

		$containerConfig->addFactory(ImageManager::class, ImageManagerFactory::class);
		$containerConfig->addFactory(InterventionImageManager::class, InterventionImageManagerFactory::class);

		$image_cfg = $event->config->get(ImageConfig::class);
		$router_cfg = $event->config->get(RouterConfig::class);
		$router_cfg->addRoute(
			'/' . $image_cfg->get('basePath', "i/") . '{uri:\S+}',
			"ON\Image\ImageManager::process",
			['GET'],
			"imagemanager",
		);
	}
}
