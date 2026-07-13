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
use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Mapping;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
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
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Mutation\DirectusMutationBinder;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Mutation\SessionFactory;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\PrimaryKey;
use ON\Data\Key;
use ON\RestApi\Support\RegistrySupportTrait;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

trait RestApiTestFixtures
{
	/** @var array<int, DataRuntime> */
	protected array $itemRuntimes = [];

	protected ?DataConversionGateway $conversionGateway = null;

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

	protected function noopHookDispatcher(Registry $registry): RestHookDispatcher
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
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->field('password', 'string')->type('string')->hidden(true)->nullable(true)->end()
			->end();
	}

	protected function createPostCollection(Registry $registry): void
	{
		$registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
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
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('body', 'string')->type('string')->nullable(true)->end()
			->field('author', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createTagCollection(Registry $registry): void
	{
		$registry->collection('tag')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createProfileCollection(Registry $registry): void
	{
		$registry->collection('profile')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
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
		$postCollection->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end();
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
		$userCollection->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end();
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
		$runtime = $this->createDataRuntime($db);
		$items = new ItemRepository(
			$registry,
			$runtime,
			$db->database(),
		);
		$this->itemRuntimes[spl_object_id($items)] = $runtime;

		return $items;
	}

	protected function createQueryParser(?CycleSqliteTestDatabase $db = null): DirectusQueryParser
	{
		$db ??= new CycleSqliteTestDatabase();

		return new DirectusQueryParser($this->createDataRuntime($db));
	}

	protected function createDataRuntime(CycleSqliteTestDatabase $db): DataRuntime
	{
		$gateway = DataConversionGateway::createDefault();
		$gateway->getMapperManager()->register(UploadPassthroughFieldType::class);
		$this->conversionGateway = $gateway;
		Mapping::setDefaultGateway($gateway);

		return (new CycleRuntimeFactory())->create(
			$db->database(),
			$gateway,
		);
	}

	protected function ensureConversionGateway(): void
	{
		$gateway = $this->conversionGateway ?? DataConversionGateway::createDefault();
		$this->conversionGateway = $gateway;
		Mapping::setDefaultGateway($gateway);
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
				Key|string $identity,
				?array $query = null,
			): ?array {
				try {
					$response = $this->getAction()(
						['collection' => $collection->getName(), 'id' => $identity instanceof Key ? PrimaryKey::of($collection)->toUrlId($identity) : (string) $identity],
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

	protected function createDirectusOperations(
		Registry $registry,
		ItemRepositoryInterface $items,
		?EventDispatcherInterface $eventDispatcher = null,
		?RestApiConfig $config = null,
	): object {
		$hookDispatcher = $this->noopHookDispatcher($registry);
		$config ??= new RestApiConfig(['databaseType' => 'sqlite']);
		$runtime = $this->runtimeForItems($items);
		$this->ensureConversionGateway();

		return new class (
			$registry,
			$items,
			$runtime,
			$config,
			$hookDispatcher,
		) {
			use RegistrySupportTrait;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private DataRuntime $runtime,
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
				Key|string $identity,
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
							'id' => $identity instanceof Key ? PrimaryKey::of($collection)->toUrlId($identity) : (string) $identity,
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

			/**
			 * @param array<string, mixed> $input
			 */
			public function create(string|CollectionInterface $collection, array $input, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];

				return $this->mutations()->create(
					$collection,
					$input,
					[],
					(bool) $options['dispatchEvents'],
					$options['output'],
				);
			}

			/**
			 * @param array<string, mixed> $input
			 */
			public function update(
				string|CollectionInterface $collection,
				Key|string $identity,
				array $input,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = PrimaryKey::of($collection)->getValue($identity);
				$options = $options + [
					'dispatchEvents' => true,
					'output' => PhpRepresentation::class,
				];

				return $this->mutations()->update(
					$collection,
					$identity,
					$input,
					[],
					(bool) $options['dispatchEvents'],
					$options['output'],
				);
			}

			public function delete(
				string|CollectionInterface $collection,
				Key|string $identity,
				array $options = []
			): bool {
				$collection = $this->getCollection($collection);
				$identity = PrimaryKey::of($collection)->getValue($identity);
				$options = $options + ['dispatchEvents' => true];

				return $this->mutations()->delete(
					$collection,
					$identity,
					(bool) $options['dispatchEvents'],
				);
			}

			private function mutations(): MutationCoordinator
			{
				$sessions = new SessionFactory($this->runtime);

				return new MutationCoordinator(
					$sessions,
					new DirectusMutationBinder($this->items, $sessions),
					new DirectusPayloadParser(),
					$this->items,
					$this->hookDispatcher,
					$this->runtime,
				);
			}

			public function serialize(CollectionInterface $collection, array $phpRow): array
			{
				return map($phpRow)
					->args($collection)
					->from(PhpRepresentation::class)
					->as(WireRepresentation::class)
					->to([]);
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
						->args($collection)
						->from(WireRepresentation::class)
						->as(PhpRepresentation::class)
						->to([]);
				} catch (ConversionException $e) {
					throw RestApiError::validationFailed([
						'_root' => [$e->getMessage()],
					]);
				}
			}
		};
	}

	protected function createRestMiddleware(
		Registry $registry,
		ItemRepositoryInterface $items,
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

		$eventDispatcher ??= $this->noopEventDispatcher();
		$hookDispatcher = $this->noopHookDispatcher($registry);
		$runtime = $this->runtimeForItems($items);
		$this->ensureConversionGateway();

		$container = new class ($registry, $items, $runtime, $config, $hookDispatcher) implements ContainerInterface {
			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private DataRuntime $runtime,
				private RestApiConfig $config,
				private RestHookDispatcher $hookDispatcher,
			) {
			}

			public function get(string $id): mixed
			{
				$fileUploads = new FileUploadEventEmitter($this->hookDispatcher);

				return match ($id) {
					ListAction::class => new ListAction($this->registry, $this->runtime, $this->config, $this->hookDispatcher),
					GetAction::class => new GetAction($this->registry, $this->runtime, $this->config, $this->hookDispatcher),
					FilesAction::class => new FilesAction(
						$this->registry,
						$this->mutations(),
						$fileUploads,
						$this->config,
					),
					CreateAction::class => new CreateAction(
						$this->registry,
						$this->mutations(),
						$this->config,
						$fileUploads,
					),
					UpdateAction::class => new UpdateAction(
						$this->registry,
						$this->mutations(),
						$this->items,
						$this->config,
						$fileUploads,
					),
					BatchUpdateAction::class => new BatchUpdateAction(
						$this->registry,
						$this->mutations(),
						$this->items,
						$this->config,
						$fileUploads,
					),
					DeleteAction::class => new DeleteAction($this->registry, $this->mutations(), $this->items, $this->config),
					BatchDeleteAction::class => new BatchDeleteAction(
						$this->registry,
						$this->mutations(),
						$this->items,
						$this->config,
					),
					default => throw new RuntimeException("Unknown service {$id}."),
				};
			}

			private function mutations(): MutationCoordinator
			{
				$sessions = new SessionFactory($this->runtime);

				return new MutationCoordinator(
					$sessions,
					new DirectusMutationBinder($this->items, $sessions),
					new DirectusPayloadParser(),
					$this->items,
					$this->hookDispatcher,
					$this->runtime,
				);
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

	protected function runtimeForItems(ItemRepositoryInterface $items): DataRuntime
	{
		$id = spl_object_id($items);
		if (! isset($this->itemRuntimes[$id])) {
			throw new RuntimeException('No DataRuntime registered for this item repository fixture.');
		}

		return $this->itemRuntimes[$id];
	}

	/**
	 * Pass-through helper: returns the Directus input payload as-is.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 * @return array<string, mixed>
	 */
	protected function m(
		Registry $registry,
		ItemRepositoryInterface $items,
		CollectionInterface|string $collection,
		array $input,
		string $mode = 'create',
		Key|string|null $id = null,
		array $files = [],
	): array {
		return $input;
	}
}
