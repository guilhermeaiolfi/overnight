<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;

class TranslationExtension extends AbstractExtension
{
	public const ID = 'translation';

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			TranslationManagerInterface::class => TranslationManagerFactory::class,
		]);
	}
}
