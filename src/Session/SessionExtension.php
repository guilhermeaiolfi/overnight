<?php

declare(strict_types=1);

namespace ON\Session;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\Init\ContainerInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

class SessionExtension extends AbstractExtension
{
	public const ID = 'session';
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::CONFIGURE, function (): void {
			$config = $this->app->config;

			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addAliases([
				SessionManagerInterface::class => SessionManager::class,
				ResolverInterface::class => NativeResolver::class,

			]);
			$containerConfig->addFactories([

			]);

		});
	}
}
