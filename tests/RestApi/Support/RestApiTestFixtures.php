<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Registry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\PayloadNormalizer;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Action\Directus\BatchDeleteAction;
use ON\RestApi\Action\Directus\BatchUpdateAction;
use ON\RestApi\Action\Directus\CreateAction;
use ON\RestApi\Action\Directus\DeleteAction;
use ON\RestApi\Action\Directus\GetAction;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\Directus\UpdateAction;
use ON\Mapper\Exception\ConversionException;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Query\DirectusQueryBuilder;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function ON\Mapper\map;

trait RestApiTestFixtures
{
	/** @var array<class-string, object> */
	protected array $mapperServices = [];

	protected function createUserCollection(Registry $registry): void
	{
		$registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->field('password', 'string')->type('string')->hidden(true)->nullable(true)->end()
			->end();
	}

	protected function createPostCollection(Registry $registry): void
	{
		$registry->collection('post')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
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
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('body', 'string')->type('string')->nullable(true)->end()
			->field('author', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createTagCollection(Registry $registry): void
	{
		$registry->collection('tag')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createProfileCollection(Registry $registry): void
	{
		$registry->collection('profile')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('displayName', 'string')->type('string')->column('display_name')->nullable(true)->end()
			->end();
	}

	protected function createFullSchema(Registry $registry): void
	{
		// Create tag collection first (no relations)
		$this->createTagCollection($registry);

		$registry->collection('post_tag')
			->field('post_id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('tag_id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->end();

		// Create comment collection (no relations of its own)
		$this->createCommentCollection($registry);

		// Create post collection with relations
		$postCollection = $registry->collection('post');
		$postCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
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
		$userCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
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

	protected function createDirectusReadActions(Registry $registry, CycleSqliteTestDatabase $db): object
	{
		$items = $this->createItems($registry, $db);
		$handlers = $this->createHandlerFactory($items);
		$config = new \ON\RestApi\RestApiConfig();
		$this->registerDirectusQueryBuilder(new DirectusQueryBuilder(
			new QueryNormalizer(),
			new DirectusQueryParser(defaultLimit: 100, maxLimit: 1000),
		));

		return new class($registry, $items, $handlers, $config) {
			private SqlQuerySpecCompiler $querySpecCompiler;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private HandlerFactory $handlers,
				private \ON\RestApi\RestApiConfig $config,
			) {
				$this->querySpecCompiler = new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
			}

			public function list(CollectionInterface $collection, QuerySpec $querySpec): array
			{
				return $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $querySpec],
					['dispatchEvents' => false],
				);
			}

			public function get(
				CollectionInterface $collection,
				PrimaryKeyValue|string $identity,
				?QuerySpec $querySpec = null,
			): ?array {
					try {
						$response = $this->getAction()(
							['collection' => $collection->getName(), 'id' => $identity instanceof PrimaryKeyValue ? $identity->toUrlId() : (string) $identity],
							['query' => $querySpec],
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

			public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
			{
				$response = $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $querySpec],
					['dispatchEvents' => false]
				);

				return $response['data'] ?? [];
			}

			private function listAction(): ListAction
			{
				return new ListAction(
					$this->registry,
					$this->items,
					$this->handlers,
					$this->querySpecCompiler,
					$this->config,
				);
			}

			private function getAction(): GetAction
			{
				return new GetAction(
					$this->registry,
					$this->items,
					$this->handlers,
					$this->config,
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
		int $defaultLimit = 100,
		int $maxLimit = 1000,
	): DirectusQueryBuilder {
		$builder = new DirectusQueryBuilder(
			new QueryNormalizer($dynamicVariables),
			new DirectusQueryParser(defaultLimit: $defaultLimit, maxLimit: $maxLimit),
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

		$container = new class($services) implements ContainerInterface {
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

				if ($this->gateway !== null && is_subclass_of($id, \ON\Mapper\Structural\MapperInterface::class)) {
					return new $id($this->gateway);
				}

				throw new \RuntimeException("Unknown service {$id}.");
			}

			public function has(string $id): bool
			{
				return isset($this->services[$id])
					|| is_subclass_of($id, \ON\Mapper\Structural\MapperInterface::class);
			}
		};

		$gateway = ConversionGateway::create(new \ON\Mapper\MapperConfig(), $container);
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
	): object {
		$this->createQueryBuilder();

		return new class(
			$registry,
			$items,
			$this->createHandlerFactory($items),
			$this->createMutationBuilder($registry, $items, $eventDispatcher),
			new \ON\RestApi\RestApiConfig(),
			$eventDispatcher,
		) {
			use RegistrySupportTrait;

			private ?FileUploadEventEmitter $fileUploadEventEmitter = null;
			private ?SqlQuerySpecCompiler $querySpecCompiler = null;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private HandlerFactory $relationHandlers,
				private DirectusMutationBuilder $mutationBuilder,
				private \ON\RestApi\RestApiConfig $config,
				private ?EventDispatcherInterface $eventDispatcher = null,
			) {}

			public function getCollection(string|\ON\ORM\Definition\Collection\CollectionInterface $collectionName): \ON\ORM\Definition\Collection\CollectionInterface
			{
				return $this->getCollectionOrThrow($this->registry, $collectionName);
			}

			public function getCollections(): array
			{
				return $this->registry->getCollections();
			}

			public function list(string|\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Query\Node\QuerySpec $querySpec, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];

				return $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $querySpec],
					$options,
				);
			}

			public function get(
				string|\ON\ORM\Definition\Collection\CollectionInterface $collection,
				\ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity,
				?\ON\RestApi\Query\Node\QuerySpec $querySpec = null,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = $collection->getPrimaryKey()->getValue($identity);
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
						['query' => $querySpec],
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

			public function aggregate(string|\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Query\Node\QuerySpec $querySpec, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];
				$response = $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $querySpec],
					$options,
				);

				return $response['data'] ?? [];
			}

			public function create(string|\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Payload\Node\MutationSpec $spec, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];
				$this->fileUploadEventEmitter()->process($spec);
				$queue = new MutationQueue();
				$events = new RestEventManager($this->eventDispatcher);
				$plan = MutationPlan::fromSpec($spec, 'create', $this->registry, $this->items, $this->relationHandlers);
				$root = null;
				if ($plan !== null) {
					if ($options['dispatchEvents']) {
						$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue));
						$events->scheduleAfterEvent($plan->getAfterMutationEvents());
					}
					$task = $queue->fill($plan->root, $events, $options['dispatchEvents']);
					$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
				}

				$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
				$events->dispatchAfterEvents();

				return map($result)
					->using(CollectionRowMapper::class, $collection)
					->from(StorageRepresentation::class)
					->as($options['output'])
					->toArray();
			}

			public function update(
				string|\ON\ORM\Definition\Collection\CollectionInterface $collection,
				\ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity,
				\ON\RestApi\Payload\Node\MutationSpec $spec,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = $collection->getPrimaryKey()->getValue($identity);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];
				$this->fileUploadEventEmitter()->process($spec);
				$queue = new MutationQueue();
				$events = new RestEventManager($this->eventDispatcher);
				$plan = MutationPlan::fromSpec($spec, 'update', $this->registry, $this->items, $this->relationHandlers, $identity);
				$root = null;
				if ($plan !== null) {
					if ($options['dispatchEvents']) {
						$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue));
						$events->scheduleAfterEvent($plan->getAfterMutationEvents());
					}
					$task = $queue->fill($plan->root, $events, $options['dispatchEvents']);
					$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
				}

				$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
				$events->dispatchAfterEvents();

				return $result !== null
					? map($result)
						->using(CollectionRowMapper::class, $collection)
						->from(StorageRepresentation::class)
						->as($options['output'])
						->toArray()
					: null;
			}

			public function delete(
				string|\ON\ORM\Definition\Collection\CollectionInterface $collection,
				\ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity,
				array $options = []
			): bool {
				$collection = $this->getCollection($collection);
				$identity = $collection->getPrimaryKey()->getValue($identity);
				$options = $options + ['dispatchEvents' => true];
				$queue = new MutationQueue();
				$events = new RestEventManager($this->eventDispatcher);
				$plan = new MutationPlan(new MutationNode(
					operation: 'delete',
					collection: $collection,
					state: new MutationState($collection, $identity->values()),
				));
				if ($options['dispatchEvents']) {
					$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue));
					$events->scheduleAfterEvent($plan->getAfterMutationEvents());
				}
				$task = $queue->fill($plan->root, $events, $options['dispatchEvents']);
				$deleted = $task instanceof MutationDeleteTaskInterface ? $task : null;

				$result = $this->items->commit($queue, fn (): bool => $deleted?->getResult() ?? true);
				$events->dispatchAfterEvents();

				return $result;
			}

			public function serialize(\ON\ORM\Definition\Collection\CollectionInterface $collection, array $phpRow): array
			{
				return map($phpRow)
					->using(CollectionRowMapper::class, $collection)
					->from(PhpRepresentation::class)
					->as(WireRepresentation::class)
					->toArray();
			}

			private function querySpecCompiler(): SqlQuerySpecCompiler
			{
				return $this->querySpecCompiler ??= new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
			}

			private function listAction(): ListAction
			{
				return new ListAction(
					$this->registry,
					$this->items,
					$this->relationHandlers,
					$this->querySpecCompiler(),
					$this->config,
					$this->eventDispatcher,
				);
			}

			private function getAction(): GetAction
			{
				return new GetAction(
					$this->registry,
					$this->items,
					$this->relationHandlers,
					$this->config,
					$this->eventDispatcher,
				);
			}

			private function fileUploadEventEmitter(): FileUploadEventEmitter
			{
				return $this->fileUploadEventEmitter ??= new FileUploadEventEmitter($this->registry, $this->eventDispatcher);
			}

			public function unserialize(\ON\ORM\Definition\Collection\CollectionInterface $collection, string|array $payload): array
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
		$config = new \ON\RestApi\RestApiConfig($options);
		$config
			->addAction('directus.list', 'GET', '{collection}', ListAction::class)
			->addAction('directus.get', 'GET', '{collection}/{id}', GetAction::class)
			->addAction('directus.create', 'POST', '{collection}', CreateAction::class)
			->addAction('directus.update', 'PATCH', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.update-post', 'POST', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.batch-update', 'PATCH', '{collection}', BatchUpdateAction::class)
			->addAction('directus.delete', 'DELETE', '{collection}/{id}', DeleteAction::class)
			->addAction('directus.batch-delete', 'DELETE', '{collection}', BatchDeleteAction::class);

		$this->registerDirectusQueryBuilder(new DirectusQueryBuilder(
			new QueryNormalizer($config->get('dynamicVariables', [])),
			new DirectusQueryParser(defaultLimit: 100, maxLimit: 1000),
		));

		$container = new class($registry, $items, $mutationBuilder, $config, $eventDispatcher) implements ContainerInterface {
			private HandlerFactory $handlers;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private DirectusMutationBuilder $mutationBuilder,
				private \ON\RestApi\RestApiConfig $config,
				private ?EventDispatcherInterface $eventDispatcher = null,
			) {
				$compiler = new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
				$this->handlers = new HandlerFactory(HandlerRegistry::defaults(), $this->items, $compiler);
			}

			public function get(string $id): mixed
			{
				return match ($id) {
					ListAction::class => new ListAction($this->registry, $this->items, $this->handlers, new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000), $this->config, $this->eventDispatcher),
					GetAction::class => new GetAction($this->registry, $this->items, $this->handlers, $this->config, $this->eventDispatcher),
					CreateAction::class => new CreateAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->eventDispatcher), $this->config, $this->eventDispatcher),
					UpdateAction::class => new UpdateAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->eventDispatcher), $this->config, $this->eventDispatcher),
					BatchUpdateAction::class => new BatchUpdateAction($this->registry, $this->items, $this->handlers, new FileUploadEventEmitter($this->registry, $this->eventDispatcher), $this->config, $this->eventDispatcher),
					DeleteAction::class => new DeleteAction($this->registry, $this->items, $this->handlers, $this->config, $this->eventDispatcher),
					BatchDeleteAction::class => new BatchDeleteAction($this->registry, $this->items, $this->handlers, $this->config, $this->eventDispatcher),
					default => throw new \RuntimeException("Unknown service {$id}."),
				};
			}

			public function has(string $id): bool
			{
				return in_array($id, [
					ListAction::class,
					GetAction::class,
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
