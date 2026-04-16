<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\ImageManager as InterventionImageManager;
use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Image\Container\ImageManagerFactory;
use ON\Image\Container\InterventionImageManagerFactory;
use ON\Router\RouterConfig;

class ImageExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
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
		$this->when('installed', [$this, 'setup']);
		$this->app->ext('container')->when('setup', [$this, 'onContainerConfig']);
		$this->app->ext('config')->when('setup', [$this, 'onConfigSetup']);
	}

	public function setup(): void
	{
		$this->dispatchStateChange('ready');
	}

	public function onContainerConfig(): void
	{
		$containerConfig = $this->app->config->get(ContainerConfig::class);

		$containerConfig->addFactory(ImageManager::class, ImageManagerFactory::class);
		$containerConfig->addFactory(InterventionImageManager::class, InterventionImageManagerFactory::class);
	}

	public function onConfigSetup(): void
	{
		$image_cfg = $this->app->config->get(ImageConfig::class);
		$router_cfg = $this->app->config->get(RouterConfig::class);
		$router_cfg->addRoute(
			'/' . $image_cfg->get('basePath', "i/") . '{uri:\S+}',
			"ON\Image\ImageManager::process",
			['GET'],
			"imagemanager",
		);
	}
}
