<?php

declare(strict_types=1);

namespace ON\Maintenance;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Init\ConfigInitEvents;
use ON\Container\ContainerConfig;
use ON\Container\Init\ContainerInitEvents;
use ON\Console\Init\ConsoleInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Middleware\Init\PipelineInitEvents;
use ON\Maintenance\Middleware\MaintenanceMiddleware;
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
		$init->on(ConfigInitEvents::SETUP, function (): void {
			$appCfg = $this->app->config->get(AppConfig::class);
			$appCfg->set("controllers.maintenance", self::class . "::" . "process");
		});

		$init->on(ContainerInitEvents::SETUP, function (): void {
			$containerConfig = $this->app->config->get(ContainerConfig::class);
			$containerConfig->set("definitions.services." . MaintenanceModeInterface::class, $this);
		});

		$init->on(PipelineInitEvents::READY, function (): void {
			$this->injectMiddleware();
		});

		if ($this->app->isCli()) {
			$init->on(ConsoleInitEvents::READY, function (): void {
				$this->app->console->addCommand(MaintenanceCommand::class);
			});
		}
	}

	public function isMaintenanceMode(): bool
	{
		return isset($_ENV["APP_MAINTENANCE"]) ? $_ENV["APP_MAINTENANCE"] == "true" : false;
	}

	public function start(\ON\Init\InitContext $context): void
	{
		$this->app->registerMethod("isMaintenanceMode", [$this, "isMaintenanceMode"]);
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
