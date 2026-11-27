<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

class TranslationExtension extends AbstractExtension
{
	public const ID = 'translation';

	protected array $configs = [];

	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			$event->containerConfig->addFactories([
				TranslationManagerInterface::class => TranslationManagerFactory::class,
			]);
		});
	}
}
