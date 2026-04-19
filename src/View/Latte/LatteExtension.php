<?php

declare(strict_types=1);

namespace ON\View\Latte;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;

class LatteExtension extends AbstractExtension
{
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		if (php_sapi_name() == 'cli') {
			return null;
		}

		$extension = new self($app, $options);
		$app->registerExtension('latte', $extension);

		return $extension;
	}

	public function requires(): array
	{
		return ['view', 'container'];
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
			$containerConfig = $this->app->config->get(ContainerConfig::class);

			$containerConfig->addFactories([
				LatteRenderer::class => LatteRendererFactory::class,
			]);

			$this->dispatchStateChange('ready');
		});
	}
}
