<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Init\Attribute\Before;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Router\RouterConfig;
use ON\View\ViewConfig;
use RuntimeException;

class FileRoutingExtension extends AbstractExtension
{
	public const ID = 'filerouting';

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$this->injectMiddleware();
	}

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$fileroutingCfg = $event->config->get(FileRoutingConfig::class);
		$router_cfg = $event->config->get(RouterConfig::class);
		if (! $fileroutingCfg->has('cachePath')) {
			$fileroutingCfg->set('cachePath', $this->app->paths->get('cache')->append('filerouting')->getAbsolutePath());
		}
		$baseUrl = trim((string) $fileroutingCfg->get('url', '__fileRouting'), '/');
		$this->registerApiRouteIfEnabled($fileroutingCfg, $router_cfg);
		$router_cfg->addRoute(
			'/' . $baseUrl . '/page',
			$fileroutingCfg->get('controller'),
			['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
			'filerouting.page',
		);
	}

	#[Before]
	public function onConfigReady(ConfigReadyEvent $event): void
	{
		$fileroutingCfg = $event->config->get(FileRoutingConfig::class);
		$router_cfg = $event->config->get(RouterConfig::class);
		$view_cfg = $event->config->get(ViewConfig::class);
		$projectPath = $this->app->paths->get('project');
		$pagesPath = $fileroutingCfg->get('pagesPath');
		if (is_string($pagesPath) && trim($pagesPath) !== '') {
			$fileroutingCfg->set('pagesPath', Path::from($pagesPath, $projectPath)->getAbsolutePath());
		}

		$configuredCachePath = $fileroutingCfg->get('cachePath');
		$cachePath = is_string($configuredCachePath) && trim($configuredCachePath) !== ''
			? Path::from($configuredCachePath, $projectPath)->getAbsolutePath()
			: $this->app->paths->get('cache')->append('filerouting')->getAbsolutePath();
		$fileroutingCfg->set('cachePath', $cachePath);

		if (! is_dir($cachePath) && ! mkdir($cachePath, 0755, true) && ! is_dir($cachePath)) {
			throw new RuntimeException(sprintf('Unable to create file routing cache directory "%s".', $cachePath));
		}

		$templateNamespace = $fileroutingCfg->get('template.namespace', 'filerouting');
		$view_cfg->set("templates.paths.{$templateNamespace}", $cachePath);

		$this->registerApiRouteIfEnabled($fileroutingCfg, $router_cfg);
	}

	protected function registerApiRouteIfEnabled(FileRoutingConfig $fileroutingCfg, RouterConfig $routerCfg): void
	{
		$exposeApi = (bool) ($this->options['exposeApi'] ?? false) || (bool) $fileroutingCfg->get('exposeApi', false);
		if (! $exposeApi || $this->hasRouteNamed($routerCfg, 'filerouting.api')) {
			return;
		}

		$baseUrl = trim((string) $fileroutingCfg->get('url', '__fileRouting'), '/');
		$routerCfg->addRoute(
			'/' . $baseUrl,
			"ON\FileRouting\Page\ApiPage::index",
			['GET'],
			'filerouting.api',
		);
	}

	protected function hasRouteNamed(RouterConfig $routerCfg, string $name): bool
	{
		if ($routerCfg->getRouteByName($name) !== null) {
			return true;
		}

		foreach ($routerCfg->getRoutesToInject() as $route) {
			if ($route->getName() === $name) {
				return true;
			}
		}

		return false;
	}

	#[On(optional: true)]
	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'file-routing',
			label: 'File routing',
			clear: function (): void {
				$config = $this->app->config->get(FileRoutingConfig::class);
				$cachePath = $config->get('cachePath', null);

				CachePathCleaner::clearDirectoryContents(is_string($cachePath) ? $cachePath : null);
			},
			priority: 60,
			description: 'Clears compiled file-routing controllers, templates, and metadata.'
		));
	}

	public function injectMiddleware(): void
	{
		// 101 because it should run just before the RouteMiddleware (100)
		$this->app->pipe("/", FileRoutingMiddleware::class, 101);
	}
}
