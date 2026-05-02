<?php

declare(strict_types=1);

namespace ON\ORM;

use ON\Application;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
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
		$init->on(ConfigConfigureEvent::class, [$this, 'onConfigConfigure']);
	}

	public function onConfigConfigure(object $event): void
	{
		$containerConfig = $event->config->get(ContainerConfig::class);
		$containerConfig->addFactory(Registry::class, RegistryFactory::class);
	}
}
