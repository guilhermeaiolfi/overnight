<?php

declare(strict_types=1);

namespace ON\GraphQL;

use ON\Data\Definition\Registry;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\Extension\AbstractExtension;
use ON\GraphQL\Middleware\GraphQLMiddleware;
use ON\GraphQL\Resolver\CycleResolver;
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use ON\GraphQL\Resolver\SqlResolver;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\RateLimit\Middleware\RateLimitMiddleware;
use ON\RateLimit\RateLimiterInterface;
use ON\Validation\CollectionValidator;
use Psr\Container\ContainerInterface;

class GraphQLExtension extends AbstractExtension
{
	public const ID = 'graphql';

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		if (! ($this->options['enabled'] ?? true)) {
			return;
		}

		$container = $event->container;

		$path = $this->options['path'] ?? '/graphql';
		$debug = $this->app->isDebug();
		$allowIntrospection = $this->options['introspection'] ?? $debug;
		$maxDepth = $this->options['maxDepth'] ?? 10;
		$maxComplexity = $this->options['maxComplexity'] ?? 100;
		$maxBatchSize = (int) ($this->options['maxBatchSize'] ?? 10);
		$maxMultipartPathDepth = (int) ($this->options['maxMultipartPathDepth'] ?? 10);
		$authorizer = $this->options['authorizer'] ?? null;

		// Build the resolver based on config
		$resolver = $this->buildResolver($container);

		// Get event dispatcher from events extension
		$eventsExt = $this->app->events;

		// Build the schema
		$registry = $container->get(Registry::class);
		$validator = new CollectionValidator(
			$this->options['validationMessages'] ?? [],
			$this->options['validationLang'] ?? 'en',
		);
		$generator = $debug
			? new GraphQLRegistryGenerator($registry, $resolver, $eventsExt->eventDispatcher, $validator, $authorizer)
			: new CachedGraphQLRegistryGenerator($registry, $resolver, $eventsExt->eventDispatcher, $validator, $authorizer);
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
			$eventsExt->eventDispatcher,
			$maxBatchSize,
			$maxMultipartPathDepth,
		);

		// Add rate limiting if the extension is installed
		if ($this->app->hasExtension('ratelimit')) {
			$rateLimiter = $container->get(RateLimiterInterface::class);
			$maxRequests = $this->options['rateLimit'] ?? 60;
			$windowSeconds = $this->options['rateLimitWindow'] ?? 60;

			$event->pipeline->pipe($path, new RateLimitMiddleware(
				$rateLimiter,
				$maxRequests,
				$windowSeconds
			), 11); // higher priority = runs before GraphQL middleware (priority 10)
		}

		$event->pipeline->pipe($path, $middleware, 10);
	}

	protected function buildResolver(ContainerInterface $container): ?GraphQLResolverInterface
	{
		$resolverType = $this->options['resolver'] ?? 'auto';

		// Custom resolver class from config
		if ($resolverType !== 'auto' && $resolverType !== 'sql' && $resolverType !== 'cycle') {
			return $container->get($resolverType);
		}

		$registry = $container->get(Registry::class);

		if (! $container->has(DatabaseManager::class)) {
			return null;
		}

		$manager = $container->get(DatabaseManager::class);
		$database = $manager->getDatabase();

		if ($database === null) {
			return null;
		}

		$defaultLimit = (int) ($this->options['defaultLimit'] ?? 100);
		$maxLimit = (int) ($this->options['maxLimit'] ?? 1000);

		if ($resolverType === 'sql') {
			return new SqlResolver($registry, $database, $defaultLimit, $maxLimit);
		}

		if ($resolverType === 'cycle') {
			$orm = $database->getResource();

			return new CycleResolver($orm, $registry, $defaultLimit, $maxLimit);
		}

		// Auto-detect based on database class
		if ($database instanceof CycleDatabase) {
			$orm = $database->getResource();

			return new CycleResolver($orm, $registry, $defaultLimit, $maxLimit);
		}

		return new SqlResolver($registry, $database, $defaultLimit, $maxLimit);
	}
}
