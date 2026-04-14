<?php

declare(strict_types=1);

namespace ON\GraphQL;

use ON\Application;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\DB\PdoDatabase;
use ON\Extension\AbstractExtension;
use ON\GraphQL\Middleware\GraphQLMiddleware;
use ON\GraphQL\Resolver\CycleResolver;
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use ON\GraphQL\Resolver\SqlResolver;
use ON\ORM\Definition\Registry;

class GraphQLExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): mixed
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
		return ['container'];
	}

	public function boot(): void
	{
		$enabled = $this->options['enabled'] ?? true;

		if (!$enabled) {
			return;
		}

		$this->app->ext('pipeline')->when('ready', [$this, 'onPipelineReady']);
		$this->setState('ready');
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

		// Build the schema
		$registry = $container->get(Registry::class);
		$generator = $debug
			? new GraphQLRegistryGenerator($registry, $resolver)
			: new CachedGraphQLRegistryGenerator($registry, $resolver);
		$schema = $generator->generate();

		// Wire up per-request cleanup
		$onComplete = $resolver !== null
			? fn() => $resolver->clearCache()
			: null;

		$middleware = new GraphQLMiddleware(
			$schema,
			$debug,
			$allowIntrospection,
			$maxDepth,
			$maxComplexity,
			$onComplete
		);

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
