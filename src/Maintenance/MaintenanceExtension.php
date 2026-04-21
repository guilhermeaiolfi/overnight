<?php

declare(strict_types=1);

namespace ON\Maintenance;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\Application;
use ON\Config\AppConfig;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Maintenance\Middleware\MaintenanceMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class MaintenanceExtension extends AbstractExtension implements MaintenanceModeInterface
{
	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);

		$app->maintenance = $extension;

		return $extension;
	}

	public function boot(): void
	{
		$this->when('installed', [$this, 'setup']);

		$this->app->ext("config")->when('setup', function () {
			$appCfg = $this->app->config->get(AppConfig::class);
			$appCfg->set("controllers.maintenance", self::class . "::" . "process");
		});

		$this->app->ext("container")->when('setup', function () {
			$containerConfig = $this->app->config->get(ContainerConfig::class);
			$containerConfig->set("definitions.services." . MaintenanceModeInterface::class, $this);
		});


		$this->app->ext("pipeline")->when('ready', function () {
			$this->injectMiddleware();
		});

		if ($this->app->isCli()) {
			$this->app->ext('console')->when('ready', function ($console) {
				$console->addCommand(MaintenanceCommand::class);
			});
		}
	}

	public function isMaintenanceMode(): bool
	{
		return isset($_ENV["APP_MAINTENANCE"]) ? $_ENV["APP_MAINTENANCE"] == "true" : false;
	}

	public function setup(): void
	{
		$this->app->registerMethod("isMaintenanceMode", [$this, "isMaintenanceMode"]);
		$this->dispatchStateChange('ready');
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
