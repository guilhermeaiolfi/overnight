<?php

declare(strict_types=1);

namespace ON\GraphQL;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\GraphQL\Middleware\GraphQLMiddleware;

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

		$this->app->ext('container')->when('setup', function () {
			$this->app->ext('container')->addDefinitions([
				GraphQLSchemaFactory::class => function ($container) {
					return new GraphQLSchemaFactory($container);
				},
			]);
		});

		$this->app->ext('pipeline')->when('ready', [$this, 'onPipelineReady']);
		$this->setState('ready');
	}

	public function onPipelineReady(): void
	{
		$path = $this->options['path'] ?? '/graphql';
		$debug = $this->app->isDebug();

		$schemaFactory = $this->app->container->get(GraphQLSchemaFactory::class);
		$schema = $schemaFactory->create($this->app->config);

		$this->app->pipe($path, new GraphQLMiddleware($schema, $debug), 10);
	}

	public function setup(): void
	{
	}
}
