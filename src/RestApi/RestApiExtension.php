<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Container\Executor\ExecutorInterface;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Middleware\PipelineExtension;
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
use ON\RestApi\Container\RestHookDispatcherFactory;
use ON\RestApi\Event\RestApiActivatedEvent;
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
	protected bool $activated = false;
	protected ?RestApiConfig $config = null;

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			ItemRepository::class => ItemRepositoryFactory::class,
			FileUploadEventEmitter::class => FileUploadEventEmitterFactory::class,
			RestHookDispatcher::class => RestHookDispatcherFactory::class,
			MutationCoordinator::class => MutationCoordinatorFactory::class,
			DirectusQueryParser::class => DirectusQueryParserFactory::class,
		]);
		$event->containerConfig->addAlias(ItemRepositoryInterface::class, ItemRepository::class);
		$event->containerConfig->addAlias(QueryParserInterface::class, DirectusQueryParser::class);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$container = $event->container;
		$this->container = $container;
		$config = $this->config = $container->get(RestApiConfig::class);
		$this->registerDefaultActions($config);

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
				'pathPrefixStripped' => true,
				'defaultLimit' => $defaultLimit,
				'maxLimit' => $maxLimit,
				'dynamicVariables' => $dynamicVariables,
				'validationMessages' => $config->get('validationMessages', []),
				'validationLang' => $config->get('validationLang', 'en'),
				'debug' => $debug,
			],
			$container->get(EventDispatcherInterface::class),
			[$this, 'ensureActivated'],
		);

		// Add rate limiting if the extension is installed
		if ($this->app->hasExtension('ratelimit')) {
			$rateLimiter = $container->get(RateLimiterInterface::class);
			$maxRequests = $config->get('rateLimit', 100);
			$windowSeconds = $config->get('rateLimitWindow', 60);

			$event->pipeline->pipe($path, new RateLimitMiddleware(
				$rateLimiter,
				$maxRequests,
				$windowSeconds
			), 11);
		}

		// Load addons
		$this->loadAddons($container, $event->pipeline, $path);

		$event->pipeline->pipe($path, $middleware, 10);

	}

	public function execute(string|RestActionInterface $action, array $params = [], mixed $payload = null, ?array $options = null): mixed
	{
		if ($this->container === null) {
			throw new RuntimeException('REST API extension cannot execute actions before the container is ready.');
		}

		$this->ensureActivated();

		if (is_string($action)) {
			$action = $this->container->get($this->resolveActionClass($action));
		}

		return $this->container->get(ExecutorInterface::class)->execute($action, [
			'params' => $params,
			'payload' => $payload,
			'options' => $options,
		]);
	}

	public function ensureActivated(): void
	{
		if ($this->activated || $this->container === null) {
			return;
		}

		$this->activated = true;
		$config = $this->config ?? $this->container->get(RestApiConfig::class);
		$dispatcher = $this->container->get(EventDispatcherInterface::class);

		$dispatcher->dispatch(new RestApiActivatedEvent(
			$this->container,
			$config,
			(string) $config->get('endpointUri', '/items'),
		));
	}

	private function resolveActionClass(string $action): string
	{
		if (class_exists($action)) {
			return $action;
		}

		$config = $this->container->get(RestApiConfig::class);
		foreach ($config->get('actions', []) as $registeredAction) {
			if (($registeredAction['name'] ?? null) === $action) {
				return $registeredAction['action'];
			}
		}

		return $action;
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
		PipelineExtension $pipeline,
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
				$pipeline->pipe($apiEndpointPath, $addon, $addon instanceof SchemaAddon ? 10 : 12);
			}
		}
	}
}
