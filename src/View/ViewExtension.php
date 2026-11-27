<?php

declare(strict_types=1);

namespace ON\View;

use League\Plates\Engine;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Init\InitContext;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Middleware\OutputTypeMiddleware;
use ON\View\Plates\PlatesEngineFactory;

class ViewExtension extends AbstractExtension
{
	public const ID = 'view';

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$viewConfig = $event->config->get(ViewConfig::class);

		$viewConfig->set(
			'templates.paths.overnight',
			[__DIR__ . DIRECTORY_SEPARATOR . 'templates']
		);
	}

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			Engine::class => PlatesEngineFactory::class,
		]);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$this->injectMiddleware();
	}

	public function start(InitContext $context): void
	{
	}

	public function injectMiddleware(): void
	{
		$this->app->pipe(OutputTypeMiddleware::class);
	}
}
