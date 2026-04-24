<?php

declare(strict_types=1);

namespace ON\ORM;

use ON\Application;
use ON\Container\Init\ContainerInitEvents;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Init\InitContext;
use ON\ORM\Definition\Registry;
use ON\ORM\Init\Event\OrmConfigureEvent;
use ON\ORM\Init\OrmInitEvents;
use Psr\Container\ContainerInterface;

class ORMExtension extends AbstractExtension
{
	public const ID = 'orm';

	protected ?ContainerInterface $container = null;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return ['container'];
	}

	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::READY, [$this, 'onContainerReady']);

		// After all listeners for OrmInitEvents::CONFIGURE have run,
		// register the fully-configured Registry in the DI container.
		$init->on(OrmInitEvents::CONFIGURE)->done(function (OrmConfigureEvent $event): void {
			if ($this->container !== null) {
				$this->container->set(Registry::class, $event->registry);
			}
		});
	}

	public function onContainerReady(ContainerReadyEvent $event): void
	{
		$this->container = $event->container;
	}

	public function start(InitContext $context): void
	{
		$registry = new Registry();

		$context->emit(OrmInitEvents::CONFIGURE, new OrmConfigureEvent($registry));
	}
}
