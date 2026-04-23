<?php

declare(strict_types=1);

namespace ON\View;

use League\Plates\Engine;
use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\Init\ContainerInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Middleware\Init\PipelineInitEvents;
use ON\Middleware\OutputTypeMiddleware;
use ON\View\Plates\PlatesEngineFactory;

class ViewExtension extends AbstractExtension
{
	public const ID = 'view';

	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return ['container', 'pipeline'];
	}

	public function register(Init $init): void
	{
		$init->on(PipelineInitEvents::READY, function (): void {
			$this->injectMiddleware();
		});

		$init->on(ContainerInitEvents::SETUP, function (): void {
			$containerConfig = $this->app->config->get(ContainerConfig::class);

			$containerConfig->addFactories([
				Engine::class => PlatesEngineFactory::class,
			]);

		});
	}

	public function start(\ON\Init\InitContext $context): void
	{
	}

	public function injectMiddleware(): void
	{
		$this->app->pipe(OutputTypeMiddleware::class);
	}
}
