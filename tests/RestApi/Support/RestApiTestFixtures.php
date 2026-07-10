<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\Container\Executor\ExecutorInterface;
use ON\Data\Database\Cycle\CycleRuntimeFactory;
use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Mapper\ConversionGateway as DataConversionGateway;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Exception\ConversionException;
use function ON\Mapper\map;
use ON\Mapper\MapperConfig;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\Mapper\Structural\MapperInterface;
use ON\RestApi\Action\Directus\BatchDeleteAction;
use ON\RestApi\Action\Directus\BatchUpdateAction;
use ON\RestApi\Action\Directus\CreateAction;
use ON\RestApi\Action\Directus\DeleteAction;
use ON\RestApi\Action\Directus\FilesAction;
use ON\RestApi\Action\Directus\GetAction;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\Directus\UpdateAction;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationNodeBuilder;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\PayloadNormalizer;
use ON\RestApi\Query\DirectusQueryBuilder;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyValue;
use ON\RestApi\Support\RegistrySupportTrait;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Throwable;

trait RestApiTestFixtures
{
	/** @var array<class-string, object> */
	protected array $mapperServices = [];

	private function noopEventDispatcher(): EventDispatcherInterface
	{
		return new class () implements EventDispatcherInterface {
			public function dispatch(object $event): object
			{
				if ($event instanceof AuthorizationAwareEventInterface) {
					$event->allow();
				}

				return $event;
			}
		};
	}

	private function noopHookDispatcher(Registry $registry): RestHookDispatcher
	{
		foreach ($registry->getCollections() as $collection) {
			RestHooks::for($collection)
				->on('list', static fn (AuthorizationAwareEventInterface $event) => $event->getAuthState() === AuthState::Pending ? $event->allow() : null)
				->on('get', static fn (AuthorizationAwareEventInterface $event) => $event->getAuthState() === AuthState::Pending ? $event->allow() : null)
				->on('create.before', static fn (AuthorizationAwareEventInterface $event) => $event->getAuthState() === AuthState::Pending ? $event->allow(true) : null)
				->on('update.before', static fn (AuthorizationAwareEventInterface $event) => $event->getAuthState() === AuthState::Pending ? $event->allow(true) : null)
				->on('delete.before', static fn (AuthorizationAwareEventInterface $event) => $event->getAuthState() === AuthState::Pending ? $event->allow(true) : null);
		}

		$container = new class () implements ContainerInterface {
			public function get(string $id): mixed
			{
				throw new RuntimeException("Unknown service {$id}.");
			}

			public function has(string $id): bool
			{
				return false;
			}
		};

		$executor = new class ($container) implements ExecutorInterface {
			public function __construct(private ContainerInterface $container)
			{
			}

			public function execute($callableOrMethodStr, array $args = [])
			{
				return $callableOrMethodStr($args['event'] ?? $args['hook'] ?? $args['payload'] ?? null);
			}

			public function getContainer(): ?ContainerInterface
			{
				return $this->container;
			}
		};

		return new RestHookDispatcher($container, $executor);
	}

	protected function createUserCollection(Registry $registry): void
	{
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->field('password', 'string')->type('string')->hidden(true)->nullable(true)->end()
			->end();
	}

	protected function createPostCollection(Registry $registry): void
	{
		$registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('user_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('content', 'text')->type('text')->nullable(true)->end()
			->field('status', 'string')->type('string')->nullable(true)->end()
			->field('created_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();
	}

	protected function createCommentCollection(Registry $registry): void
	{
		$registry->collection('comment')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('body', 'string')->type('string')->nullable(true)->end()
			->field('author', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createTagCollection(Registry $registry): void
	{
		$registry->collection('tag')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createProfileCollection(Registry $registry): void
	{
		$registry->collection('profile')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('displayName', 'string')->type('string')->column('display_name')->nullable(true)->end()
			->end();
	}

	protected function createFullSchema(Registry $registry): void
	{
		// Create tag collection first (no relations)
		$this->createTagCollection($registry);

		$registry->collection('post_tag')
			->primaryKey('post_id', 'tag_id')
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('tag_id', 'int')->type('int')->nullable(false)->end()
			->end();

		// Create comment collection (no relations of its own)
		$this->createCommentCollection($registry);

		// Create post collection with relations
		$postCollection = $registry->collection('post');
		$postCollection->primaryKey('id');
		$postCollection->field('id', 'int')->type('int')->nullable(false)->end();
		$postCollection->field('user_id', 'int')->type('int')->nullable(false)->end();
		$postCollection->field('title', 'string')->type('string')->nullable(true)->end();
		$postCollection->field('content', 'text')->type('text')->nullable(true)->end();
		$postCollection->field('status', 'string')->type('string')->nullable(true)->end();
		$postCollection->field('created_at', 'datetime')->type('datetime')->nullable(true)->end();

		// post hasMany comments: innerKey('id')->outerKey('post_id')
		$postCollection->hasMany('comments', 'comment')
			->innerKey('id')
			->outerKey('post_id')
			->end();

		// post belongsTo author (user): innerKey('user_id')->outerKey('id')
		$postCollection->belongsTo('author', 'user')
			->innerKey('user_id')
			->outerKey('id');

		// post M2M tags via post_tag
		$postCollection->relation('tags', M2MRelation::class)
			->collection('tag')
			->innerKey('id')
			->outerKey('id')
			->through('post_tag')
				->innerKey('post_id')
				->outerKey('tag_id')
				->end()
			->end();

		// Create user collection with hasMany posts
		$userCollection = $registry->collection('user');
		$userCollection->primaryKey('id');
		$userCollection->field('id', 'int')->type('int')->nullable(false)->end();
		$userCollection->field('name', 'string')->type('string')->nullable(true)->end();
		$userCollection->field('email', 'string')->type('string')->nullable(true)->end();
		$userCollection->field('password', 'string')->type('string')->hidden(true)->nullable(true)->end();

		// user hasMany posts: innerKey('id')->outerKey('user_id')
		$userCollection->hasMany('posts', 'post')
			->innerKey('id')
			->outerKey('user_id')
			->end();
	}

	protected function createTestDatabase(): CycleSqliteTestDatabase
	{
		return new CycleSqliteTestDatabase([
			'user' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
					'email' => 'TEXT',
					'password' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'password' => 'secret1'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com', 'password' => 'secret2'],
				],
			],
			'post' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'user_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'content' => 'TEXT',
					'status' => 'TEXT',
					'created_at' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'PHP Tips', 'content' => 'Learn PHP', 'status' => 'published', 'created_at' => '2025-01-10 10:00:00'],
					['id' => 2, 'user_id' => 1, 'title' => 'Draft Post', 'content' => 'WIP', 'status' => 'draft', 'created_at' => '2025-02-11 10:00:00'],
					['id' => 3, 'user_id' => 2, 'title' => 'GraphQL Guide', 'content' => 'Learn GraphQL', 'status' => 'published', 'created_at' => '2026-01-12 10:00:00'],
				],
			],
		]);
	}

	protected function createFullDatabase(): CycleSqliteTestDatabase
	{
		return new CycleSqliteTestDatabase([
			'user' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
					'email' => 'TEXT',
					'password' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'password' => 'secret1'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com', 'password' => 'secret2'],
					['id' => 3, 'name' => 'Bob', 'email' => 'bob@test.com', 'password' => 'secret3'],
				],
			],
			'post' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'user_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'content' => 'TEXT',
					'status' => 'TEXT',
					'created_at' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'PHP Tips', 'content' => 'Learn PHP', 'status' => 'published', 'created_at' => '2025-01-10 10:00:00'],
					['id' => 2, 'user_id' => 1, 'title' => 'Draft Post', 'content' => 'WIP', 'status' => 'draft', 'created_at' => '2025-02-11 10:00:00'],
					['id' => 3, 'user_id' => 2, 'title' => 'GraphQL Guide', 'content' => 'Learn GraphQL', 'status' => 'published', 'created_at' => '2026-01-12 10:00:00'],
				],
			],
			'comment' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'post_id' => 'INTEGER NOT NULL',
					'body' => 'TEXT',
					'author' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'post_id' => 1, 'body' => 'Great tips!', 'author' => 'Alice'],
					['id' => 2, 'post_id' => 1, 'body' => 'Very helpful', 'author' => 'Bob'],
					['id' => 3, 'post_id' => 3, 'body' => 'Nice guide', 'author' => 'John'],
				],
			],
			'tag' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'PHP'],
					['id' => 2, 'name' => 'GraphQL'],
					['id' => 3, 'name' => 'REST'],
				],
			],
			'post_tag' => [
				'columns' => [
					'post_id' => 'INTEGER NOT NULL',
					'tag_id' => 'INTEGER NOT NULL',
				],
				'rows' => [
					['post_id' => 1, 'tag_id' => 1],
					['post_id' => 1, 'tag_id' => 2],
					['post_id' => 3, 'tag_id' => 2],
					['post_id' => 3, 'tag_id' => 3],
				],
			],
		]);
	}

	protected function createProfileDatabase(): CycleSqliteTestDatabase
	{
		return new CycleSqliteTestDatabase([
			'profile' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'display_name' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'display_name' => 'Alice'],
					['id' => 2, 'display_name' => 'Bob'],
				],
			],
		]);
	}

	protected function createItems(Registry $registry, CycleSqliteTestDatabase $db): ItemRepository
	{
		return new ItemRepository(
			$registry,
			$db->database(),
		);
	}

	protected function createHandlerFactory(ItemRepositoryInterface $items): HandlerFactory
	{
		return new HandlerFactory(
			HandlerRegistry::defaults(),
			$items,
			new SqlQuerySpecCompiler($items->getDatabase(), 100, 1000)
		);
	}

	protected function createQueryParser(?CycleSqliteTestDatabase $db = null): DirectusQueryParser
	{
		$db ??= new CycleSqliteTestDatabase();

		return new DirectusQueryParser($this->createDataRuntime($db));
	}

	protected function createDataRuntime(CycleSqliteTestDatabase $db): DataRuntime
	{
		return (new CycleRuntimeFactory())->create(
			$db->database(),
			DataConversionGateway::createDefault(),
		);
	}

	protected function createDirectusReadActions(Registry $registry, CycleSqliteTestDatabase $db, ?RestApiConfig $config = null): object
	{
		$config ??= new RestApiConfig(['databaseType' => 'sqlite']);
		$hookDispatcher = $this->noopHookDispatcher($registry);
		$runtime = $this->createDataRuntime($db);

		return new class ($registry, $runtime, $config, $hookDispatcher) {
			public function __construct(
				private Registry $registry,
				private DataRuntime $runtime,
				private RestApiConfig $config,
				private RestHookDispatcher $hookDispatcher,
			) {
			}

			public function list(CollectionInterface $collection, array $query = []): array
			{
				return $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $query],
					['dispatchEvents' => false],
				);
			}

			public function get(
				CollectionInterface $collection,
				PrimaryKeyValue|string $identity,
				?array $query = null,
			): ?array {
				try {
					$response = $this->getAction()(
						['collection' => $collection->getName(), 'id' => $identity instanceof PrimaryKeyValue ? $identity->toUrlId() : (string) $identity],
						['query' => $query ?? []],
						['dispatchEvents' => false]
					);
				} catch (RestApiError $error) {
					if ($error->getHttpStatus() === 404) {
						return null;
					}

					throw $error;
				}

				return $response['data'] ?? null;
			}

			public function aggregate(CollectionInterface $collection, array $query = []): array
			{
				$response = $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $query],
					['dispatchEvents' => false]
				);

				return $response['data'] ?? [];
			}

			private function listAction(): ListAction
			{
				return new ListAction(
					$this->registry,
					$this->runtime,
					$this->config,
					$this->hookDispatcher,
				);
			}

			private function getAction(): GetAction
			{
				return new GetAction(
					$this->registry,
					$this->runtime,
					$this->config,
					$this->hookDispatcher,
				);
			}
		};
	}

	protected function createMutationBuilder(
		Registry $registry,
		ItemRepositoryInterface $items,
		?EventDispatcherInterface $eventDispatcher = null,
	): DirectusMutationBuilder {
		$builder = new DirectusMutationBuilder(
			$registry,
			$items,
			new PayloadNormalizer($this->createHandlerFactory($items), $registry),
		);
		$this->registerDirectusMutationBuilder($builder);

		return $builder;
	}

	protected function registerDirectusMutationBuilder(DirectusMutationBuilder $builder): void
	{
		$this->registerMapperService(DirectusMutationBuilder::class, $builder);
	}

	protected function createQueryBuilder(
		array $dynamicVariables = [],
	): DirectusQueryBuilder {
		$builder = new DirectusQueryBuilder(
			new QueryNormalizer($dynamicVariables),
		);
		$this->registerDirectusQueryBuilder($builder);

		return $builder;
	}

	protected function registerDirectusQueryBuilder(DirectusQueryBuilder $builder): void
	{
		$this->registerMapperService(DirectusQueryBuilder::class, $builder);
	}

	/**
	 * @param class-string $class
	 */
	protected function registerMapperService(string $class, object $service): void
	{
		$this->mapperServices[$class] = $service;
		$services = &$this->mapperServices;

		$container = new class ($services) implements ContainerInterface {
			public ?ConversionGateway $gateway = null;

			/**
			 * @param array<class-string, object> $services
			 */
			public function __construct(private array &$services)
			{
			}

			public function get(string $id): mixed
			{
				if (isset($this->services[$id])) {
					return $this->services[$id];
				}

				if ($this->gateway !== null && is_subclass_of($id, MapperInterface::class)) {
					return new $id($this->gateway);
				}

				throw new RuntimeException("Unknown service {$id}.");
			}

			public function has(string $id): bool
			{
				return isset($this->services[$id])
					|| is_subclass_of($id, MapperInterface::class);
			}
		};

		$gateway = ConversionGateway::create(new MapperConfig(), $container);
		$container->gateway = $gateway;
		foreach (array_keys($this->mapperServices) as $mapperClass) {
			$gateway->getMappers()->replace($mapperClass);
		}
		ConversionGateway::setInstance($gateway);
	}

	protected function createDirectusOperations(
		Registry $registry,
		ItemRepositoryInterface $items,
		?EventDispatcherInterface $eventDispatcher = null,
		?RestApiConfig $config = null,
	): object {
		$eventDispatcher ??= $this->noopEventDispatcher();
		$hookDispatcher = $this->noopHookDispatcher($registry);
		$config ??= new RestApiConfig(['databaseType' => 'sqlite']);
		$runtime = (new CycleRuntimeFactory())->create(
			$items->getDatabase(),
			DataConversionGateway::createDefault(),
		);

		return new class (
			$registry,
			$items,
			$runtime,
			$this->createHandlerFactory($items),
			$this->createMutationBuilder($registry, $items, $eventDispatcher),
			$config,
			$hookDispatcher,
		) {
			use RegistrySupportTrait;

			private ?FileUploadEventEmitter $fileUploadEventEmitter = null;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private DataRuntime $runtime,
				private HandlerFactory $relationHandlers,
				private DirectusMutationBuilder $mutationBuilder,
				private RestApiConfig $config,
				private RestHookDispatcher $hookDispatcher,
			) {
			}

			public function getCollection(string|CollectionInterface $collectionName): CollectionInterface
			{
				return $this->getCollectionOrThrow($this->registry, $collectionName);
			}

			public function getCollections(): array
			{
				return $this->registry->getCollections();
			}

			public function list(string|CollectionInterface $collection, array $query = [], array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];

				return $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $query],
					$options,
				);
			}

			public function get(
				string|CollectionInterface $collection,
				PrimaryKeyValue|string $identity,
				?array $query = null,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = PrimaryKey::of($collection)->getValue($identity);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];

				try {
					$response = $this->getAction()(
						[
							'collection' => $collection->getName(),
							'id' => $identity instanceof PrimaryKeyValue ? $identity->toUrlId() : (string) $identity,
						],
						['query' => $query ?? []],
						$options,
					);
				} catch (RestApiError $error) {
					if ($error->getHttpStatus() === 404) {
						return null;
					}

					throw $error;
				}

				return $response['data'] ?? null;
			}

			public function aggregate(string|CollectionInterface $collection, array $query = [], array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];
				$response = $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $query],
					$options,
				);

				return $response['data'] ?? [];
			}

			public function create(string|CollectionInterface $collection, MutationSpec $spec, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];
				$this->fileUploadEventEmitter()->process($spec);
				$queue = new MutationQueue();
				$afterHooksTx = $this->hookDispatcher->start();
				$rootNode = MutationNodeBuilder::fromSpec($spec, 'create', $this->registry, $this->items, $this->relationHandlers);
				$root = null;
				if ($rootNode !== null) {
					$task = $queue->fill($rootNode, $this->hookDispatcher, $afterHooksTx, $options['dispatchEvents']);
					$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
				}

				try {
					$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
					$afterHooksTx->flush();
				} catch (Throwable $throwable) {
					$afterHooksTx->rollback();

					throw $throwable;
				}

				return map($result)
					->using(CollectionRowMapper::class, $collection)
					->from(StorageRepresentation::class)
					->as($options['output'])
					->toArray();
			}

			public function update(
				string|CollectionInterface $collection,
				PrimaryKeyValue|string $identity,
				MutationSpec $spec,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = PrimaryKey::of($collection)->getValue($identity);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];
				$this->fileUploadEventEmitter()->process($spec);
				$queue = new MutationQueue();
				$afterHooksTx = $this->hookDispatcher->start();
				$rootNode = MutationNodeBuilder::fromSpec($spec, 'update', $this->registry, $this->items, $this->relationHandlers, $identity);
				$root = null;
				if ($rootNode !== null) {
					$task = $queue->fill($rootNode, $this->hookDispatcher, $afterHooksTx, $options['dispatchEvents']);
					$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
				}

				try {
					$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
					$afterHooksTx->flush();
				} catch (Throwable $throwable) {
					$afterHooksTx->rollback();

					throw $throwable;
				}

				return $result !== null
					? map($result)
						->using(CollectionRowMapper::class, $collection)
						->from(StorageRepresentation::class)
						->as($options['output'])
						->toArray()
					: null;
			}

			public function delete(
				string|CollectionInterface $collection,
				PrimaryKeyValue|string $identity,
				array $options = []
			): bool {
				$collection = $this->getCollection($collection);
				$identity = PrimaryKey::of($collection)->getValue($identity);
				$options = $options + ['dispatchEvents' => true];
				$queue = new MutationQueue();
				$afterHooksTx = $this->hookDispatcher->start();
				$rootNode = new MutationNode(
					operation: 'delete',
					collection: $collection,
					state: new MutationState($collection, $identity->values()),
				);
				$task = $queue->fill($rootNode, $this->hookDispatcher, $afterHooksTx, $options['dispatchEvents']);
				$deleted = $task instanceof MutationDeleteTaskInterface ? $task : null;

				try {
					$result = $this->items->commit($queue, fn (): bool => $deleted?->getResult() ?? true);
					$afterHooksTx->flush();
				} catch (Throwable $throwable) {
					$afterHooksTx->rollback();

					throw $throwable;
				}

				return $result;
			}

			public function serialize(CollectionInterface $collection, array $phpRow): array
			{
				return map($phpRow)
					->using(CollectionRowMapper::class, $collection)
					->from(PhpRepresentation::class)
					->as(WireRepresentation::class)
					->toArray();
			}

			private function listAction(): ListAction
			{
				return new ListAction(
					$this->registry,
					$this->runtime,
					$this->config,
					$this->hookDispatcher,
				);
			}

			private function getAction(): GetAction
			{
				return new GetAction(
					$this->registry,
					$this->runtime,
					$this->config,
					$this->hookDispatcher,
				);
			}

			private function fileUploadEventEmitter(): FileUploadEventEmitter
			{
				return $this->fileUploadEventEmitter ??= new FileUploadEventEmitter(
					$this->registry,
					$this->hookDispatcher
				);
			}

			public function unserialize(CollectionInterface $collection, string|array $payload): array
			{
				if (is_string($payload)) {
					if ($payload === '') {
						return [];
					}

					$payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
					if (! is_array($payload)) {
						throw RestApiError::invalidJson();
					}
				}

				try {
					return map($payload)
						->using(CollectionRowMapper::class, $collection)
						->from(WireRepresentation::class)
						->as(PhpRepresentation::class)
						->toArray();
				} catch (ConversionException $e) {
					throw RestApiError::validationFailed([
						$e->getField() ?? '_root' => [$e->getMessage()],
					]);
				}
			}
		};
	}

	protected function createRestMiddleware(
		Registry $registry,
		ItemRepositoryInterface $items,
		DirectusMutationBuilder $mutationBuilder,
		array $options = ['endpointUri' => '/items'],
		?EventDispatcherInterface $eventDispatcher = null,
	): RestMiddleware {
		$config = new RestApiConfig($options);
		$config
			->addAction('directus.list', 'GET', '{collection}', ListAction::class)
			->addAction('directus.get', 'GET', '{collection}/{id}', GetAction::class)
			->addAction('directus.files', 'POST', 'files', FilesAction::class)
			->addAction('directus.create', 'POST', '{collection}', CreateAction::class)
			->addAction('directus.update', 'PATCH', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.update-post', 'POST', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.batch-update', 'PATCH', '{collection}', BatchUpdateAction::class)
			->addAction('directus.delete', 'DELETE', '{collection}/{id}', DeleteAction::class)
			->addAction('directus.batch-delete', 'DELETE', '{collection}', BatchDeleteAction::class);

		$this->registerDirectusQueryBuilder(new DirectusQueryBuilder(
			new QueryNormalizer($config->get('dynamicVariables', [])),
		));

		$eventDispatcher ??= $this->noopEventDispatcher();
		$hookDispatcher = $this->noopHookDispatcher($registry);
		$runtime = (new CycleRuntimeFactory())->create(
			$items->getDatabase(),
			DataConversionGateway::createDefault(),
		);

		$container = new class ($registry, $items, $runtime, $mutationBuilder, $config, $hookDispatcher, $eventDispatcher) implements ContainerInterface {
			private HandlerFactory $handlers;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private DataRuntime $runtime,
				private DirectusMutationBuilder $mutationBuilder,
				private RestApiConfig $config,
				private RestHookDispatcher $hookDispatcher,
				private ?EventDispatcherInterface $eventDispatcher = null,
			) {
				$compiler = new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
				$this->handlers = new HandlerFactory(HandlerRegistry::defaults(), $this->items, $compiler);
			}

			public function get(string $id): mixed
			{
				return match ($id) {
					ListAction::class => new ListAction($this->registry, $this->runtime, $this->config, $this->hookDispatcher),
					GetAction::class => new GetAction($this->registry, $this->runtime, $this->config, $this->hookDispatcher),
					FilesAction::class => new FilesAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->hookDispatcher), $this->config, $this->hookDispatcher),
					CreateAction::class => new CreateAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->hookDispatcher), $this->config, $this->hookDispatcher),
					UpdateAction::class => new UpdateAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->hookDispatcher), $this->config, $this->hookDispatcher),
					BatchUpdateAction::class => new BatchUpdateAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->hookDispatcher), $this->config, $this->hookDispatcher),
					DeleteAction::class => new DeleteAction($this->registry, $this->items, $this->handlers, $this->config, $this->hookDispatcher),
					BatchDeleteAction::class => new BatchDeleteAction($this->registry, $this->items, $this->handlers, $this->config, $this->hookDispatcher),
					default => throw new RuntimeException("Unknown service {$id}."),
				};
			}

			public function has(string $id): bool
			{
				return in_array($id, [
					ListAction::class,
					GetAction::class,
					FilesAction::class,
					CreateAction::class,
					UpdateAction::class,
					BatchUpdateAction::class,
					DeleteAction::class,
					BatchDeleteAction::class,
				], true);
			}
		};

		return new RestMiddleware(
			new RestActionRouter($config->get('actions', [])),
			static function (string $action, array $params = [], mixed $payload = null, ?array $options = null) use ($container): mixed {
				return $container->get($action)($params, $payload, $options);
			},
			$options,
			$eventDispatcher,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 */
	protected function buildMutationSpec(
		Registry $registry,
		ItemRepositoryInterface $items,
		CollectionInterface|string $collection,
		array $input,
		string $mode = 'create',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
		?EventDispatcherInterface $eventDispatcher = null,
		bool $unserializeWire = true,
	): MutationSpec {
		$collection = is_string($collection) ? $registry->getCollection($collection) : $collection;

		return $this->createMutationBuilder($registry, $items)->build(
			$collection,
			$input,
			$mode,
			$id,
			$files,
			$unserializeWire ? WireRepresentation::class : PhpRepresentation::class,
			PhpRepresentation::class,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 */
	protected function m(
		Registry $registry,
		ItemRepositoryInterface $items,
		CollectionInterface|string $collection,
		array $input,
		string $mode = 'create',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
	): MutationSpec {
		return $this->buildMutationSpec($registry, $items, $collection, $input, $mode, $id, $files);
	}
}
