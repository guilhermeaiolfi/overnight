<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\RateLimit\Middleware\RateLimitMiddleware;
use ON\RateLimit\RateLimiterInterface;
use ON\RestApi\Action\Directus\BatchDeleteAction;
use ON\RestApi\Action\Directus\BatchUpdateAction;
use ON\RestApi\Action\Directus\CreateAction;
use ON\RestApi\Action\Directus\DeleteAction;
use ON\RestApi\Action\Directus\FilesAction;
use ON\RestApi\Action\Directus\GetAction;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\Directus\UpdateAction;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Addon\RestApiAddonInterface;
use ON\RestApi\Addon\SchemaAddon;
use ON\RestApi\Container\DirectusQueryParserFactory;
use ON\RestApi\Container\FileUploadEventEmitterFactory;
use ON\RestApi\Container\ItemRepositoryFactory;
use ON\RestApi\Container\MutationCoordinatorFactory;
use ON\RestApi\Container\RestApiExecutorFactory;
use ON\RestApi\Container\RestHookDispatcherFactory;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\Parser\QueryParserInterface;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;

class RestApiExtension extends AbstractExtension
{
	public const ID = 'restapi';

	protected ?ContainerInterface $container = null;
	protected ?RestApiConfig $config = null;
	private ?RestApiExecutor $fallbackExecutor = null;

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			ItemRepository::class => ItemRepositoryFactory::class,
			FileUploadEventEmitter::class => FileUploadEventEmitterFactory::class,
			RestHookDispatcher::class => RestHookDispatcherFactory::class,
			MutationCoordinator::class => MutationCoordinatorFactory::class,
			DirectusQueryParser::class => DirectusQueryParserFactory::class,
			RestApiExecutor::class => RestApiExecutorFactory::class,
		]);
		$event->containerConfig->addAlias(ItemRepositoryInterface::class, ItemRepository::class);
		$event->containerConfig->addAlias(QueryParserInterface::class, DirectusQueryParser::class);
		$event->containerConfig->addAlias(RestApiExecutorInterface::class, RestApiExecutor::class);
	}

	#[On]
	public function onContainerReady(ContainerReadyEvent $event): void
	{
		$this->configureActions($event->container);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$container = $event->container;
		// PipelineReady can fire inside ContainerReady before this extension's listener runs.
		$config = $this->configureActions($container);
		$executor = $this->resolveExecutor($container);

		$path = $config->get('endpointUri', '/items');
		$debug = $this->app->isDebug();
		$defaultLimit = $config->get('defaultLimit', 100);
		$maxLimit = $config->get('maxLimit', 1000);
		$dynamicVariables = $config->get('dynamicVariables', []);

		$middleware = new RestMiddleware(
			new RestActionRouter($config->get('actions', [])),
			[$executor, 'execute'],
			[
				'endpointUri' => $path,
				'pathPrefixStripped' => true,
				'defaultLimit' => $defaultLimit,
				'maxLimit' => $maxLimit,
				'dynamicVariables' => $dynamicVariables,
				'validationMessages' => $config->get('validationMessages', []),
				'validationLang' => $config->get('validationLang', 'en'),
				'debug' => $debug,
			],
			$container->get(EventDispatcherInterface::class),
			[$executor, 'ensureActivated'],
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
			throw new RuntimeException('REST API extension cannot execute actions before the container is ready.');
		}

		return $this->resolveExecutor($this->container)->execute($action, $params, $payload, $options);
	}

	public function ensureActivated(): void
	{
		if ($this->container !== null) {
			$this->resolveExecutor($this->container)->ensureActivated();
		}
	}

	private function configureActions(ContainerInterface $container): RestApiConfig
	{
		if ($this->container === $container && $this->config !== null) {
			return $this->config;
		}

		$this->container = $container;
		$this->config = $container->get(RestApiConfig::class);
		$this->fallbackExecutor = null;
		$this->registerDefaultActions($this->config);

		return $this->config;
	}

	private function resolveExecutor(ContainerInterface $container): RestApiExecutorInterface
	{
		if ($container->has(RestApiExecutorInterface::class)) {
			return $container->get(RestApiExecutorInterface::class);
		}

		return $this->fallbackExecutor ??= new RestApiExecutor(
			$container,
			$this->config ?? $container->get(RestApiConfig::class),
			$container->get(EventDispatcherInterface::class),
		);
	}

	protected function registerDefaultActions(RestApiConfig $config): void
	{
		if ($config->hasActions()) {
			return;
		}

		$config
			->addAction('directus.list', 'GET', '{collection}', ListAction::class)
			->addAction('directus.get', 'GET', '{collection}/{id}', GetAction::class)
			->addAction('directus.files', 'POST', 'files', FilesAction::class)
			->addAction('directus.create', 'POST', '{collection}', CreateAction::class)
			->addAction('directus.update', 'PATCH', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.batch-update', 'PATCH', '{collection}', BatchUpdateAction::class)
			->addAction('directus.delete', 'DELETE', '{collection}/{id}', DeleteAction::class)
			->addAction('directus.batch-delete', 'DELETE', '{collection}', BatchDeleteAction::class);
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
				$this->app->pipe($apiEndpointPath, $addon, $addon instanceof SchemaAddon ? 10 : 12);
			}
		}
	}
}
