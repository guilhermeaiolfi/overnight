<?php

declare(strict_types=1);

namespace ON\GraphQL\Extension;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\GraphQL\GraphQLSchemaFactory;
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
		$path = $this->options['path'] ?? '/graphql';
		$enabled = $this->options['enabled'] ?? true;

		if (! $enabled) {
			return;
		}

		$this->app->ext('container')->when('setup', function () {
			$this->app->ext('container')->addDefinitions([
				GraphQLSchemaFactory::class => function ($container) {
					$config = $this->app->config;

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

		$schemaFactory = $this->app->container->get(GraphQLSchemaFactory::class);
		$schema = $schemaFactory->create($this->app->config);

		$this->app->pipe($path, new GraphQLMiddleware($schema), 10);

		$this->setState('ready');
	}

	public function setup(): void
	{
	}
}
