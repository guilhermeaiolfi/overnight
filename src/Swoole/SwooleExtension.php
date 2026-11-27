<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\After;
use ON\Init\Init;
use ON\RequestStackInterface;
use ON\Runtime\SwooleRuntime;

class SwooleExtension extends AbstractExtension
{
	public const ID = 'swoole';

	public function register(Init $init): void
	{
		$this->app->registerRuntime('swoole', new SwooleRuntime(
			$this->options['host'] ?? '127.0.0.1',
			$this->options['port'] ?? 9501,
			$this->options['settings'] ?? [],
			$this->options['serverFactory'] ?? null,
		));
	}

	#[After]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addAlias(RequestStackInterface::class, SwooleRequestStack::class);
	}
}
