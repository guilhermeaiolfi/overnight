<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Application;
use ON\Config\Init\ConfigInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
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
		$init->on(ConfigInitEvents::CONFIGURE, [$this, 'onConfigConfigure']);
	}

	public function onConfigConfigure(): void
	{
		$filerouting_cfg = $this->app->config->get(FileRoutingConfig::class);
		$router_cfg = $this->app->config->get(RouterConfig::class);
		$view_cfg = $this->app->config->get(ViewConfig::class);
		$template_namespace = $filerouting_cfg->get('template.namespace', 'filerouting');
		$cache_path = $filerouting_cfg->get('cachePath');

		if (! is_dir($cache_path) && ! mkdir($cache_path, 0777, true) && ! is_dir($cache_path)) {
			throw new RuntimeException(sprintf('Unable to create file routing cache directory "%s".', $cache_path));
		}

		$view_cfg->set("templates.paths.{$template_namespace}", $cache_path);
		$router_cfg->addRoute(
			'/' . $filerouting_cfg->get('url', "__fileRouting"),
			"ON\FileRouting\Page\ApiPage::index",
			['GET'],
			"filerouting.api",
		);
	}

	public function start(\ON\Init\InitContext $context): void
	{
		// 101 because it should run just before the RouteMiddleware (100)
		$this->app->pipe("/", FileRoutingMiddleware::class, 101);

	}
}
