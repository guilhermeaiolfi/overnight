<?php

declare(strict_types=1);

namespace ON\Mapper;

use ON\Application;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Mapper\Container\ConversionGatewayFactory;

final class MapperExtension extends AbstractExtension
{
	public const ID = 'mapper';

	public function __construct(
		protected Application $app,
		protected array $options = [],
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, [$this, 'onConfigConfigure']);
		$init->on(ContainerConfigureEvent::class, [$this, 'onContainerConfigure']);
		$init->on(ConfigReadyEvent::class, [$this, 'onConfigReady']);
	}

	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		ConversionGateway::configure($event->config->get(MapperConfig::class));
	}

	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			ConversionGateway::class => ConversionGatewayFactory::class,
		]);
	}

	public function onConfigReady(ConfigReadyEvent $event): void
	{
		ConversionGateway::configure($event->config->get(MapperConfig::class));
	}
}
