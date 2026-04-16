<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Application;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\DB\PdoDatabase;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\ORM\Definition\Registry;
use ON\RateLimit\Middleware\RateLimitMiddleware;
use ON\RateLimit\RateLimiterInterface;
use ON\RestApi\Addon\RestApiAddonInterface;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Resolver\CycleRestResolver;
use ON\RestApi\Resolver\RestResolverInterface;
use ON\RestApi\Resolver\SqlFilterParser;
use ON\RestApi\Resolver\SqlRestResolver;

class RestApiExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);
		$app->registerExtension('restapi', $extension);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return ['container', 'events'];
	}

	public function boot(): void
	{
		$enabled = $this->options['enabled'] ?? true;

		if (!$enabled) {
			return;
		}

		$this->app->ext('pipeline')->when('ready', [$this, 'onPipelineReady']);
		$this->dispatchStateChange('ready');
	}

	public function onPipelineReady(): void
	{
		$container = $this->app->container;

		$path = $this->options['path'] ?? '/items';
		$debug = $this->app->isDebug();
		$defaultLimit = $this->options['defaultLimit'] ?? 100;
		$maxLimit = $this->options['maxLimit'] ?? 1000;

		$resolver = $this->buildResolver();

		$eventsExt = $this->app->events;
		$registry = $container->get(Registry::class);

		// Wire up per-request cleanup via event
		if ($resolver !== null) {
			$eventsExt->registerListener('restapi.request.complete', function () use ($resolver) {
				$resolver->clearCache();
			});
		}

		$middleware = new RestMiddleware(
			$registry,
			$resolver,
			$eventsExt->eventDispatcher,
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
			$maxRequests = $this->options['rateLimit'] ?? 100;
			$windowSeconds = $this->options['rateLimitWindow'] ?? 60;

			$this->app->pipe($path, new RateLimitMiddleware(
				$rateLimiter,
				$maxRequests,
				$windowSeconds
			), 11);
		}

		// Load addons
		$this->loadAddons($registry, $resolver, $eventsExt->eventDispatcher, $path);

		$this->app->pipe($path, $middleware, 10);
	}

	protected function loadAddons(
		Registry $registry,
		?RestResolverInterface $resolver,
		?\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher,
		string $basePath
	): void {
		$addons = $this->options['addons'] ?? [];
		$container = $this->app->container;

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

			if (!class_exists($addonClass)) {
				continue;
			}

			// Pass basePath to addons that need it (like SchemaAddon)
			$addonOptions['basePath'] = $addonOptions['basePath'] ?? $basePath;

			/** @var RestApiAddonInterface $addon */
			$addon = $container->get($addonClass);
			$addon->register($addonOptions);

			if ($addon instanceof \Psr\Http\Server\MiddlewareInterface) {
				$this->app->pipe($basePath, $addon, 12);
			}
		}
	}

	protected function buildResolver(): ?RestResolverInterface
	{
		$container = $this->app->container;
		$resolverType = $this->options['resolver'] ?? 'auto';

		// Custom resolver class from config
		if ($resolverType !== 'auto' && $resolverType !== 'sql' && $resolverType !== 'cycle') {
			return $container->get($resolverType);
		}

		$registry = $container->get(Registry::class);

		if (!$container->has(DatabaseManager::class)) {
			return null;
		}

		$manager = $container->get(DatabaseManager::class);
		$database = $manager->getDatabase();

		if ($database === null) {
			return null;
		}

		$defaultLimit = $this->options['defaultLimit'] ?? 100;
		$maxLimit = $this->options['maxLimit'] ?? 1000;

		if ($resolverType === 'cycle') {
			$orm = $database->getResource();
			return new CycleRestResolver($orm, $registry, $defaultLimit, $maxLimit);
		}

		if ($resolverType === 'sql') {
			return new SqlRestResolver(
				$registry,
				$database,
				new SqlFilterParser(),
				$defaultLimit,
				$maxLimit
			);
		}

		// Auto-detect based on database class
		if ($database instanceof CycleDatabase) {
			$orm = $database->getResource();
			return new CycleRestResolver($orm, $registry, $defaultLimit, $maxLimit);
		}

		return new SqlRestResolver(
			$registry,
			$database,
			new SqlFilterParser(),
			$defaultLimit,
			$maxLimit
		);
	}

	public function setup(): void
	{
	}
}
