<?php

declare(strict_types=1);

namespace ON\ORM;

use ON\Application;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\ORM\Container\RegistryFactory;
use ON\ORM\Definition\Registry;

class ORMExtension extends AbstractExtension
{
	public const ID = 'orm';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, [$this, 'onContainerConfigure']);
	}

	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactory(Registry::class, RegistryFactory::class);
	}
}
