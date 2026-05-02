<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\RateLimit\Middleware\RateLimitMiddleware;
use ON\RateLimit\RateLimiterInterface;
use ON\RestApi\Addon\RestApiAddonInterface;
use ON\RestApi\Container\RestApiServiceFactory;
use ON\RestApi\Container\RestResolverFactory;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Resolver\RestResolverInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

class RestApiExtension extends AbstractExtension
{
	public const ID = 'restapi';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, [$this, 'onConfigConfigure']);
		$init->on(PipelineReadyEvent::class, [$this, 'onPipelineReady']);
	}

	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$containerConfig = $event->config->get(ContainerConfig::class);
		$containerConfig->addFactories([
			RestApiService::class => RestApiServiceFactory::class,
			RestResolverInterface::class => RestResolverFactory::class,
		]);
	}

	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$container = $event->container;
		$config = $container->get(RestApiConfig::class);

		$path = $config->get('path', '/items');
		$debug = $this->app->isDebug();
		$defaultLimit = $config->get('defaultLimit', 100);
		$maxLimit = $config->get('maxLimit', 1000);

		$service = $container->get(RestApiService::class);

		// Wire up per-request cleanup via event
		if ($service->getResolver() !== null) {
			$this->app->events->registerListener('restapi.request.complete', function () use ($service) {
				$service->clearCache();
			});
		}

		$middleware = new RestMiddleware(
			$service,
			[
				'path' => $path,
				'defaultLimit' => $defaultLimit,
				'maxLimit' => $maxLimit,
				'debug' => $debug,
			]
		);

		// Add rate limiting if the extension is installed
		if ($this->app->hasExtension('ratelimit')) {
			$rateLimiter = $container->get(RateLimiterInterface::class);
			$maxRequests = $config->get('rateLimit', 100);
			$windowSeconds = $config->get('rateLimitWindow', 60);

			$this->app->pipe($path, new RateLimitMiddleware(
				$rateLimiter,
				$maxRequests,
				$windowSeconds
			), 11);
		}

		// Load addons
		$this->loadAddons($container, $service, $path);

		$this->app->pipe($path, $middleware, 10);

	}

	protected function loadAddons(
		ContainerInterface $container,
		RestApiService $service,
		string $apiEndpointPath
	): void {
		$addons = $container->get(RestApiConfig::class)->get('addons', []);

		foreach ($addons as $key => $value) {
			// Support both formats:
			// [RevisionAddon::class]                              → class at numeric key, no options
			// [RevisionAddon::class => ['table' => 'activity']]   → class as key, options as value
			if (is_int($key)) {
				$addonClass = $value;
				$addonOptions = [];
			} else {
				$addonClass = $key;
				$addonOptions = is_array($value) ? $value : [];
			}

			if (! class_exists($addonClass)) {
				continue;
			}

			// Pass apiEndpointPath to addons that need it (like SchemaAddon)
			$addonOptions['apiEndpointPath'] = $addonOptions['apiEndpointPath'] ?? $apiEndpointPath;

			/** @var RestApiAddonInterface $addon */
			$addon = $container->get($addonClass);
			$addon->register($service, $addonOptions);

			if ($addon instanceof MiddlewareInterface) {
				$this->app->pipe($apiEndpointPath, $addon, 12);
			}
		}
	}

}
