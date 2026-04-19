<?php

declare(strict_types=1);

namespace ON\GraphQL;

use ON\Application;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\DB\PdoDatabase;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\GraphQL\Middleware\GraphQLMiddleware;
use ON\GraphQL\Resolver\CycleResolver;
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use ON\GraphQL\Resolver\SqlResolver;
use ON\ORM\Definition\Registry;
use ON\RateLimit\Middleware\RateLimitMiddleware;
use ON\RateLimit\RateLimiterInterface;

class GraphQLExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);
		$app->registerExtension('graphql', $extension);

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
			$this->dispatchStateChange('ready');
			return;
		}

		$this->app->ext('pipeline')->when('ready', [$this, 'onPipelineReady']);
	}

	public function onPipelineReady(): void
	{
		$container = $this->app->container;

		$path = $this->options['path'] ?? '/graphql';
		$debug = $this->app->isDebug();
		$allowIntrospection = $this->options['introspection'] ?? $debug;
		$maxDepth = $this->options['maxDepth'] ?? 10;
		$maxComplexity = $this->options['maxComplexity'] ?? 100;

		// Build the resolver based on config
		$resolver = $this->buildResolver();

		// Get event dispatcher from events extension
		$eventsExt = $this->app->events;

		// Build the schema
		$registry = $container->get(Registry::class);
		$generator = $debug
			? new GraphQLRegistryGenerator($registry, $resolver, $eventsExt->eventDispatcher)
			: new CachedGraphQLRegistryGenerator($registry, $resolver, $eventsExt->eventDispatcher);
		$schema = $generator->generate();

		// Wire up per-request cleanup via event
		if ($resolver !== null) {
			$this->app->events->registerListener('graphql.query.complete', function () use ($resolver) {
				$resolver->clearCache();
			});
		}

		$middleware = new GraphQLMiddleware(
			$schema,
			$debug,
			$allowIntrospection,
			$maxDepth,
			$maxComplexity,
			$eventsExt->eventDispatcher
		);

		// Add rate limiting if the extension is installed
		if ($this->app->hasExtension('ratelimit')) {
			$rateLimiter = $container->get(RateLimiterInterface::class);
			$maxRequests = $this->options['rateLimit'] ?? 60;
			$windowSeconds = $this->options['rateLimitWindow'] ?? 60;

			$this->app->pipe($path, new RateLimitMiddleware(
				$rateLimiter,
				$maxRequests,
				$windowSeconds
			), 11); // higher priority = runs before GraphQL middleware (priority 10)
		}

		$this->app->pipe($path, $middleware, 10);
	}

	protected function buildResolver(): ?GraphQLResolverInterface
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

		if ($resolverType === 'sql') {
			return new SqlResolver($registry, $database);
		}

		if ($resolverType === 'cycle') {
			$orm = $database->getResource();
			return new CycleResolver($orm, $registry);
		}

		// Auto-detect based on database class
		if ($database instanceof CycleDatabase) {
			$orm = $database->getResource();
			return new CycleResolver($orm, $registry);
		}

		return new SqlResolver($registry, $database);
	}

	public function setup(): void
	{
	}
}
