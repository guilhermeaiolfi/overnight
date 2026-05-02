<?php

declare(strict_types=1);

namespace ON\View\Latte;

use ON\Application;
use ON\Container\ContainerConfig;

use ON\Config\Init\Event\ConfigConfigureEvent;

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

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			$containerConfig = $event->config->get(ContainerConfig::class);
			$viewConfig = $event->config->get(ViewConfig::class);

			$viewConfig->add('latte.extension', 'latte');

			$containerConfig->addFactories([
				LatteRenderer::class => LatteRendererFactory::class,
			]);

		});
	}
}
