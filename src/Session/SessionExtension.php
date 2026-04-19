<?php

declare(strict_types=1);

namespace ON\Session;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;

class SessionExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);

		$app->registerExtension('session', $extension);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
			$config = $this->app->config;

			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addAliases([
				SessionManagerInterface::class => SessionManager::class,
				ResolverInterface::class => NativeResolver::class,

			]);
			$containerConfig->addFactories([

			]);

			$this->dispatchStateChange('ready');
		});
	}
}
