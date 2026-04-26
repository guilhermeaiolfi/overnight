<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Config\Init\ConfigInitEvents;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\Init\ContainerInitEvents;
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
		$init->on(ConfigInitEvents::CONFIGURE, function (ConfigConfigureEvent $event): void {
			$config = $event->config;

			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				TranslationManagerInterface::class => TranslationManagerFactory::class,
			]);

			$translationConfig = $config->get(TranslationConfig::class);

		});
	}

}
