<?php

declare(strict_types=1);

namespace ON\Session;

use ON\Application;
use ON\Container\Init\Event\ContainerConfigureEvent;
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
		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			$event->containerConfig->addFactories([
				SessionInterface::class => SessionFactory::class,
			]);
		});
	}
}
