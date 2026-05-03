<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Config\Init\Event\ConfigConfigureEvent;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Path;
use ON\Router\RouterConfig;
use ON\View\ViewConfig;
use RuntimeException;

class FileRoutingExtension extends AbstractExtension
{
	public const ID = 'app';

	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}
	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, [$this, 'onConfigConfigure']);
		$init->on(PipelineReadyEvent::class, function (): void {
			$this->injectMiddleware();
		});
	}

	public function onConfigConfigure(): void
	{
		$fileroutingCfg = $this->app->config->get(FileRoutingConfig::class);
		$router_cfg = $this->app->config->get(RouterConfig::class);
		$view_cfg = $this->app->config->get(ViewConfig::class);
		$template_namespace = $fileroutingCfg->get('template.namespace', 'filerouting');
		$configuredCachePath = $fileroutingCfg->get('cachePath');
		
		$cache_path = $this->app->paths->get('cache')->append('filerouting')->absolute();
		if ($configuredCachePath !== null && trim($configuredCachePath) !== '') {
			$cache_path = Path::from($configuredCachePath, $this->app->paths->get('project'))
				->absolute();
		}
		$fileroutingCfg->set('cachePath', $cache_path);

		if (! is_dir($cache_path) && ! mkdir($cache_path, 0777, true) && ! is_dir($cache_path)) {
			throw new RuntimeException(sprintf('Unable to create file routing cache directory "%s".', $cache_path));
		}

		$view_cfg->set("templates.paths.{$template_namespace}", $cache_path);
		$router_cfg->addRoute(
			'/' . $fileroutingCfg->get('url', "__fileRouting"),
			"ON\FileRouting\Page\ApiPage::index",
			['GET'],
			"filerouting.api",
		);
	}

	public function injectMiddleware(): void
	{
		// 101 because it should run just before the RouteMiddleware (100)
		$this->app->pipe("/", FileRoutingMiddleware::class, 101);
	}
}
