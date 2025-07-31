<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\View\ViewConfig;

class FileRoutingExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app);
		$app->registerExtension('app', $extension);

		return $extension;
	}

	public function boot(): void
	{
		/*$this->app->ext('config')->when('setup', function () {

			$viewConfig = $this->app->config->get(ViewConfig::class);

			$viewConfig->set("templates.paths.fileRouting", [__DIR__ . '/../pages']);
		});*/
	}

	public function setup(): void
	{
		// 101 because it should run just before the RouteMiddleware (100)
		$this->app->pipe("/", FileRoutingMiddleware::class, 101);

		$this->setState('ready');
	}
}
