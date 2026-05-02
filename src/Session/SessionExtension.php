<?php

declare(strict_types=1);

namespace ON\Session;

use ON\Application;

use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Session\Container\SessionFactory;

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
		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			$containerConfig = $event->config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				SessionInterface::class => SessionFactory::class,
			]);
		});
	}
}
