<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Router\RouterConfig;
use ON\View\ViewConfig;

class FileRoutingExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app);
		$app->registerExtension('app', $extension);

		return $extension;
	}

	public function boot(): void
	{
		$this->when('installed', [$this, 'setup']);
		$this->app->ext('config')->when('setup', [$this, 'onConfigSetup']);
	}

	public function onConfigSetup(): void
	{
		$filerouting_cfg = $this->app->config->get(FileRoutingConfig::class);
		$router_cfg = $this->app->config->get(RouterConfig::class);
		$view_cfg = $this->app->config->get(ViewConfig::class);
		$template_namespace = $filerouting_cfg->get('template.namespace', 'filerouting');

		$view_cfg->set("templates.paths.{$template_namespace}", $filerouting_cfg->get('cachePath'));
		$router_cfg->addRoute(
			'/' . $filerouting_cfg->get('url', "__fileRouting"),
			"ON\FileRouting\Page\ApiPage::index",
			['GET'],
			"filerouting.api",
		);
	}

	public function setup(): void
	{
		// 101 because it should run just before the RouteMiddleware (100)
		$this->app->pipe("/", FileRoutingMiddleware::class, 101);

		$this->dispatchStateChange('ready');
	}
}
