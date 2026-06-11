<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Application;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

class TranslationExtension extends AbstractExtension
{
	public const ID = 'translation';

	protected int $type = self::TYPE_EXTENSION;
	protected Application $app;
	protected array $options;
	protected array $configs = [];

	public function __construct(
		Application $app,
		array $options = []
	) {
		$this->options = $options;
		$this->app = $app;
	}
	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			$event->containerConfig->addFactories([
				TranslationManagerInterface::class => TranslationManagerFactory::class,
			]);
		});
	}

}
