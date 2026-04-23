<?php

declare(strict_types=1);

namespace ON\View\Latte;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\Init\ContainerInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\View\ViewConfig;

class LatteExtension extends AbstractExtension
{
	public const ID = 'latte';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return ['view', 'container'];
	}

	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::CONFIGURE, function (): void {
			$containerConfig = $this->app->config->get(ContainerConfig::class);
			$viewConfig = $this->app->config->get(ViewConfig::class);

			$viewConfig->add('latte.extension', 'latte');

			$containerConfig->addFactories([
				LatteRenderer::class => LatteRendererFactory::class,
			]);

		});
	}
}
