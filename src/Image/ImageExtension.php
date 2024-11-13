<?php

declare(strict_types=1);

namespace ON\Image;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use ON\Image\Container\ImageManagerFactory;
use ON\Router\Route;
use ON\Router\RouterConfig;

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

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', [$this, 'onContainerConfig']);
		$this->app->ext('config')->when('setup', [$this, 'onConfigSetup']);
	}

	public function setup(): void
	{
		$this->setState('ready');
	}

	public function onContainerConfig(): void
	{
		$containerConfig = $this->app->config->get(ContainerConfig::class);

		$containerConfig->addFactory(ImageManager::class, ImageManagerFactory::class);
	}

	public function onConfigSetup(): void
	{
		$image_cfg = $this->app->config->get(ImageConfig::class);
		$router_cfg = $this->app->config->get(RouterConfig::class);
		$router_cfg->addRoute(new Route(
			'/' . $image_cfg->get('basePath', "i/") . '{uri:\S+}',
			"ON\Image\ImageManager::process",
			['GET'],
			"imagemanager",
		));
	}

	public static function getSubscribedEvents(): array
	{
		return [
			//"core.extensions.container.config" => 'onContainerConfig',
		];
	}
}
