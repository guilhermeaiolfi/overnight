<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Application;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Container\Executor\ExecutorInterface;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\RateLimit\Middleware\RateLimitMiddleware;
use ON\RateLimit\RateLimiterInterface;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Addon\RestApiAddonInterface;
use ON\RestApi\Container\DirectusMutationBuilderFactory;
use ON\RestApi\Container\DirectusQueryBuilderFactory;
use ON\RestApi\Container\HandlerFactoryFactory;
use ON\RestApi\Container\ItemRepositoryFactory;
use ON\RestApi\Container\SqlQuerySpecCompilerFactory;
use ON\RestApi\Action\Directus\BatchDeleteAction;
use ON\RestApi\Action\Directus\BatchUpdateAction;
use ON\RestApi\Action\Directus\CreateAction;
use ON\RestApi\Action\Directus\DeleteAction;
use ON\RestApi\Action\Directus\GetAction;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\Directus\UpdateAction;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Handler\HandlerFactory;
use ON\Mapper\ConversionGateway;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Query\DirectusQueryBuilder;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

class RestApiExtension extends AbstractExtension
{
	public const ID = 'restapi';

	protected ?ContainerInterface $container = null;

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
			ItemRepository::class => ItemRepositoryFactory::class,
			ItemRepositoryInterface::class => ItemRepositoryFactory::class,
			SqlQuerySpecCompiler::class => SqlQuerySpecCompilerFactory::class,
			HandlerFactory::class => HandlerFactoryFactory::class,
			DirectusMutationBuilder::class => DirectusMutationBuilderFactory::class,
			DirectusQueryBuilder::class => DirectusQueryBuilderFactory::class,
		]);
	}

	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$container = $event->container;
		$this->container = $container;
		$config = $container->get(RestApiConfig::class);
		$this->registerDefaultActions($config);
		$this->registerDirectusMutationBuilder($container);
		$this->registerDirectusQueryBuilder($container);

		$path = $config->get('endpointUri', '/items');
		$debug = $this->app->isDebug();
		$defaultLimit = $config->get('defaultLimit', 100);
		$maxLimit = $config->get('maxLimit', 1000);
		$dynamicVariables = $config->get('dynamicVariables', []);

		$middleware = new RestMiddleware(
			new RestActionRouter($config->get('actions', [])),
			[$this, 'execute'],
			[
				'endpointUri' => $path,
				'defaultLimit' => $defaultLimit,
				'maxLimit' => $maxLimit,
				'dynamicVariables' => $dynamicVariables,
				'validationMessages' => $config->get('validationMessages', []),
				'validationLang' => $config->get('validationLang', 'en'),
				'debug' => $debug,
			],
			$container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
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
		$this->loadAddons($container, $path);

		$this->app->pipe($path, $middleware, 10);

	}

	public function execute(string|RestActionInterface $action, array $params = [], mixed $payload = null, ?array $options = null): mixed
	{
		if ($this->container === null) {
			throw new \RuntimeException('REST API extension cannot execute actions before the container is ready.');
		}

		if (is_string($action)) {
			$action = $this->container->get($action);
		}

		return $this->container->get(ExecutorInterface::class)->execute($action, [
			'params' => $params,
			'payload' => $payload,
			'options' => $options,
		]);
	}

	protected function registerDefaultActions(RestApiConfig $config): void
	{
		if ($config->hasActions()) {
			return;
		}

		$config
			->addAction('directus.list', 'GET', '{collection}', ListAction::class)
			->addAction('directus.get', 'GET', '{collection}/{id}', GetAction::class)
			->addAction('directus.create', 'POST', '{collection}', CreateAction::class)
			->addAction('directus.update', 'PATCH', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.batch-update', 'PATCH', '{collection}', BatchUpdateAction::class)
			->addAction('directus.delete', 'DELETE', '{collection}/{id}', DeleteAction::class)
			->addAction('directus.batch-delete', 'DELETE', '{collection}', BatchDeleteAction::class);
	}

	protected function registerDirectusMutationBuilder(ContainerInterface $container): void
	{
		if (! $container->has(ConversionGateway::class)) {
			return;
		}

		$container->get(ConversionGateway::class)
			->structuralMappers()
			->replace($container->get(DirectusMutationBuilder::class));
	}

	protected function registerDirectusQueryBuilder(ContainerInterface $container): void
	{
		if (! $container->has(ConversionGateway::class)) {
			return;
		}

		$container->get(ConversionGateway::class)
			->structuralMappers()
			->replace($container->get(DirectusQueryBuilder::class));
	}

	protected function loadAddons(
		ContainerInterface $container,
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
			$addon->register($addonOptions);

			if ($addon instanceof MiddlewareInterface) {
				$this->app->pipe($apiEndpointPath, $addon, 12);
			}
		}
	}
}
