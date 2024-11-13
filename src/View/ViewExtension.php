<?php

declare(strict_types=1);

namespace ON\View;

use League\Plates\Engine;
use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Middleware\OutputTypeMiddleware;
use ON\View\Plates\PlatesEngineFactory;

class ViewExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		if (php_sapi_name() == 'cli') {
			return false;
		}
		$class = self::class;
		$extension = new $class($app, $options);
		$app->registerExtension('view', $extension); // register shortcut
		$app->view = $extension;

		return $extension;
	}

	public function boot(): void
	{
		$this->app->ext('pipeline')->when('ready', function () {
			$this->injectMiddleware();
		});

		$this->app->ext('container')->when('setup', function () {
			$containerConfig = $this->app->config->get(ContainerConfig::class);

			// TODO: plates and lattes should be an extension by its own
			$containerConfig->addFactories([
				Engine::class => PlatesEngineFactory::class,
			]);
		});
	}

	public function setup(): void
	{
		$this->setState('ready');
	}

	public function injectMiddleware(): void
	{
		$this->app->pipe(OutputTypeMiddleware::class);
	}
}
