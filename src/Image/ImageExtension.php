<?php

declare(strict_types=1);

namespace ON\Image;

use ON\Application;
use ON\Config\RouterConfig;
use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use ON\Router\Route;

class ImageExtension extends AbstractExtension implements EventSubscriberInterface
{
	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function setup($counter): bool
	{
		$image_cfg = $this->app->config->get(ImageConfig::class);
		$router_cfg = $this->app->config->get(RouterConfig::class);
		$router_cfg->addRoute(new Route(
			'/' . $image_cfg->get('basePath', "i/") . '{uri:\S+}',
			"ON\Image\ImageManager::process",
			['GET'],
			"imagemanager",
		));

		return true;
	}

	public function onContainerConfig($event): void
	{
		$containerConfig = $event->getSubject();

		$containerConfig->addFactory(ImageManager::class, ImageManagerFactory::class);
	}

	public static function getSubscribedEvents(): array
	{
		return [
			"core.extensions.container.config" => 'onContainerConfig',
		];
	}
}
