<?php

declare(strict_types=1);

namespace ON\Session;

use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Session\Container\SessionFactory;

class SessionExtension extends AbstractExtension
{
	public const ID = 'session';

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			SessionInterface::class => SessionFactory::class,
		]);
	}
}
