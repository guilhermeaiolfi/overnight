<?php

declare(strict_types=1);

namespace ON\Maintenance;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Maintenance\Container\MaintenanceModeFactory;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Maintenance\Middleware\MaintenanceMiddleware;
use ON\Console\Init\Event\ConsoleReadyEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class MaintenanceExtension extends AbstractExtension implements MaintenanceModeInterface
{
	public const ID = 'maintenance';

	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}
	public function register(Init $init): void
	{
		$this->app->registerMethod("isMaintenanceMode", [$this, "isMaintenanceMode"]);

		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			$appCfg = $event->config->get(AppConfig::class);
			$appCfg->set("controllers.maintenance", self::class . "::" . "process");
			$containerConfig = $event->config->get(ContainerConfig::class);
			$containerConfig->addFactory(MaintenanceModeInterface::class, MaintenanceModeFactory::class);
		});

		$init->on(PipelineReadyEvent::class, function (): void {
			$this->injectMiddleware();
		});

		if ($this->app->isCli()) {
			$init->on(ConsoleReadyEvent::class, function (): void {
				$this->app->console->addCommand(MaintenanceCommand::class);
			});
		}
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
		$html = $this->render("template.html");

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
