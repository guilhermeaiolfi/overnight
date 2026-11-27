<?php

declare(strict_types=1);

namespace ON\Maintenance;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\Config\AppConfig;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Init\Init;
use ON\Maintenance\Container\MaintenanceModeFactory;
use ON\Maintenance\Middleware\MaintenanceMiddleware;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class MaintenanceExtension extends AbstractExtension implements MaintenanceModeInterface
{
	public const ID = 'maintenance';

	public function register(Init $init): void
	{
		$this->app->registerMethod("isMaintenanceMode", [$this, "isMaintenanceMode"]);
	}

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$appCfg = $event->config->get(AppConfig::class);
		$appCfg->set("controllers.maintenance", self::class . "::" . "process");
	}

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactory(MaintenanceModeInterface::class, MaintenanceModeFactory::class);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$this->injectMiddleware();
	}

	#[On(optional: true)]
	public function onConsoleReady(ConsoleReadyEvent $event): void
	{
		$event->console->addCommand(MaintenanceCommand::class);
	}

	public function isMaintenanceMode(): bool
	{
		return isset($_ENV["APP_MAINTENANCE"]) ? $_ENV["APP_MAINTENANCE"] == "true" : false;
	}

	public function injectMiddleware(): void
	{
		$this->app->pipe("/", MaintenanceMiddleware::class, 900);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$html = $this->render(__DIR__ . '/template.html');

		return new HtmlResponse($html, 503);
	}

	protected function render($file): string
	{
		try {
			$level = ob_get_level();
			ob_start();

			include($file);

			$content = ob_get_clean();

			return $content;
		} catch (Throwable $e) {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}

			throw $e;
		}

		return "";
	}
}
