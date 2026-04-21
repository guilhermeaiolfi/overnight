<?php

declare(strict_types=1);

namespace ON\View;

use League\Plates\Engine;
use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Middleware\OutputTypeMiddleware;
use ON\View\Plates\PlatesEngineFactory;

class ViewExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		if (php_sapi_name() == 'cli') {
			return null;
		}

		$extension = new self($app, $options);
		$app->registerExtension('view', $extension);
		$app->view = $extension;

		return $extension;
	}

	public function boot(): void
	{
		$this->when('installed', [$this, 'setup']);

		$this->app->ext('pipeline')->when('ready', function () {
			$this->injectMiddleware();
		});

		$this->app->ext('container')->when('setup', function () {
			$containerConfig = $this->app->config->get(ContainerConfig::class);

			$containerConfig->addFactories([
				Engine::class => PlatesEngineFactory::class,
			]);

		});
	}

	public function setup(): void
	{
		$this->dispatchStateChange('ready');
	}

	public function injectMiddleware(): void
	{
		$this->app->pipe(OutputTypeMiddleware::class);
	}
}
