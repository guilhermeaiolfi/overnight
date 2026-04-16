<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;

class TranslationExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;
	protected Application $app;
	protected array $options;
	protected array $configs = [];

	public function __construct(
		Application $app,
		array $options = []
	) {
		$this->options = $options;
		$this->app = $app;
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);
		$app->registerExtension('translation', $extension);

		return $extension;
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
			$config = $this->app->config;

			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				TranslationManagerInterface::class => TranslationManagerFactory::class,
			]);

			$translationConfig = $config->get(TranslationConfig::class);

			$this->dispatchStateChange('ready');
		});
	}

	public function setup(): void
	{


	}
}
