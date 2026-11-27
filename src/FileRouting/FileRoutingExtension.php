<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Router\RouterConfig;
use ON\View\ViewConfig;
use RuntimeException;

class FileRoutingExtension extends AbstractExtension
{
	public const ID = 'filerouting';

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$fileroutingCfg = $event->config->get(FileRoutingConfig::class);
		$router_cfg = $event->config->get(RouterConfig::class);
		$view_cfg = $event->config->get(ViewConfig::class);
		$template_namespace = $fileroutingCfg->get('template.namespace', 'filerouting');
		$configuredCachePath = $fileroutingCfg->get('cachePath');

		$cache_path = $this->app->paths->get('cache')->append('filerouting')->getAbsolutePath();
		if ($configuredCachePath !== null && trim($configuredCachePath) !== '') {
			$cache_path = Path::from($configuredCachePath, $this->app->paths->get('project'))
				->getAbsolutePath();
		}
		$fileroutingCfg->set('cachePath', $cache_path);

		if (! is_dir($cache_path) && ! mkdir($cache_path, 0755, true) && ! is_dir($cache_path)) {
			throw new RuntimeException(sprintf('Unable to create file routing cache directory "%s".', $cache_path));
		}

		$view_cfg->set("templates.paths.{$template_namespace}", $cache_path);
		$baseUrl = trim((string) $fileroutingCfg->get('url', '__fileRouting'), '/');
		$this->registerApiRouteIfEnabled($fileroutingCfg, $router_cfg);
		$router_cfg->addRoute(
			'/' . $baseUrl . '/page',
			$fileroutingCfg->get('controller'),
			['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
			'filerouting.page',
		);
	}

	#[On]
	public function onConfigReady(ConfigReadyEvent $event): void
	{
		$fileroutingCfg = $event->config->get(FileRoutingConfig::class);
		$router_cfg = $event->config->get(RouterConfig::class);

		$this->registerApiRouteIfEnabled($fileroutingCfg, $router_cfg);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		// 101 because it should run just before the RouteMiddleware (100)
		$event->pipeline->pipe('/', FileRoutingMiddleware::class, 101);
	}

	#[On(optional: true)]
	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'file-routing',
			label: 'File routing',
			clear: function (): void {
				$config = $this->app->ext(ConfigExtension::class)->get(FileRoutingConfig::class);
				$cachePath = $config->get('cachePath', null);

				CachePathCleaner::clearDirectoryContents(is_string($cachePath) ? $cachePath : null);
			},
			priority: 60,
			description: 'Clears compiled file-routing controllers, templates, and metadata.'
		));
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
}
