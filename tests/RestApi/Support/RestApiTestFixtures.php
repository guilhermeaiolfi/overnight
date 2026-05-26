<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Registry;
use ON\ORM\Typecast\CollectionTypecast;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Mapping\CollectionMapper;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\MutationSpecUnserializer;
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
use ON\RestApi\Support\FormatOutputTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Serialize\CollectionSerializer;
use ON\RestApi\Support\AuthorizationGuard;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

trait RestApiTestFixtures
{
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
			new CollectionMapper(new CollectionTypecast()),
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

		return new class($registry, $items, $handlers, $config) {
			private DirectusQueryParser $queryParser;
			private QueryNormalizer $queryNormalizer;
			private SqlQuerySpecCompiler $querySpecCompiler;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private HandlerFactory $handlers,
				private \ON\RestApi\RestApiConfig $config,
			) {
				$this->queryParser = new DirectusQueryParser(defaultLimit: 100, maxLimit: 1000);
				$this->queryNormalizer = new QueryNormalizer();
				$this->querySpecCompiler = new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
			}

			public function list(CollectionInterface $collection, QuerySpec $querySpec): array
			{
				$response = $this->listAction()(
					['collection' => $collection->getName()],
					['query' => $querySpec],
					['serialize' => false, 'dispatchEvents' => false]
				);

				return [
					'items' => $response['data'] ?? [],
					'meta' => $response['meta'] ?? [],
				];
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
							['serialize' => false, 'dispatchEvents' => false]
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
					['serialize' => false, 'dispatchEvents' => false]
				);

				return $response['data'] ?? [];
			}

			private function listAction(): ListAction
			{
				return new ListAction(
					$this->registry,
					$this->items,
					$this->handlers,
					$this->queryParser,
					$this->queryNormalizer,
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
					$this->queryParser,
					$this->queryNormalizer,
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
		$handlers = $this->createHandlerFactory($items);

		return new DirectusMutationBuilder(
			$registry,
			$items,
			new PayloadNormalizer($handlers, $registry),
			new MutationSpecUnserializer($registry),
		);
	}

	protected function createDirectusOperations(
		Registry $registry,
		ItemRepositoryInterface $items,
		?EventDispatcherInterface $eventDispatcher = null,
	): object {
		return new class(
			$registry,
			$items,
			$this->createHandlerFactory($items),
			$this->createMutationBuilder($registry, $items, $eventDispatcher),
			new \ON\RestApi\RestApiConfig(),
			$eventDispatcher
		) {
			use FormatOutputTrait;
			use RegistrySupportTrait;

			private ?FileUploadEventEmitter $fileUploadEventEmitter = null;

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
				$options += ['serialize' => false, 'dispatchEvents' => true];
				if ($options['dispatchEvents']) {
					$event = new ItemList($collection, $querySpec, $options);
					$this->eventDispatcher?->dispatch($event);
					if ($this->eventDispatcher !== null) {
						AuthorizationGuard::assert($event);
					}
					$querySpec = $event->getQuerySpec();
					$responseOptions = $event->getOptions();

					if ($event->isDefaultPrevented()) {
						return [
							'items' => $this->formatResponseRows($collection, $event->getResult() ?? [], $responseOptions),
							'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
						];
					}
				}

				$result = $this->directusReads()->list($collection, $querySpec);
				if (isset($event)) {
					$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
					$responseOptions = $event->getOptions();
				}

				$result['items'] = $this->formatResponseRows($collection, $result['items'] ?? [], $responseOptions ?? $options);

				return $result;
			}

			public function get(
				string|\ON\ORM\Definition\Collection\CollectionInterface $collection,
				\ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity,
				?\ON\RestApi\Query\Node\QuerySpec $querySpec = null,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = $collection->getPrimaryKey()->getValue($identity);
				$options += ['serialize' => false, 'dispatchEvents' => true];

				if (!$options['dispatchEvents']) {
					return $this->formatResponseRow($collection, $this->directusReads()->get($collection, $identity, $querySpec), $options);
				}

				$event = new ItemGet($collection, $identity, $querySpec, $options);
				$this->eventDispatcher?->dispatch($event);
				if ($this->eventDispatcher !== null) {
					AuthorizationGuard::assert($event);
				}
				$querySpec = $event->getQuerySpec() ?? $querySpec;
				$responseOptions = $event->getOptions();

				if ($event->isDefaultPrevented()) {
					return $this->formatResponseRow($collection, $event->getResult(), $responseOptions);
				}

				$event->setResult($this->directusReads()->get($collection, $identity, $querySpec));

				return $this->formatResponseRow($collection, $event->getResult(), $responseOptions);
			}

			public function aggregate(string|\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Query\Node\QuerySpec $querySpec, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options += ['serialize' => false, 'dispatchEvents' => true];
				if (!$options['dispatchEvents']) {
					return $this->directusReads()->aggregate($collection, $querySpec);
				}

				$event = new ItemList($collection, $querySpec, $options);
				$this->eventDispatcher?->dispatch($event);
				if ($this->eventDispatcher !== null) {
					AuthorizationGuard::assert($event);
				}
				$querySpec = $event->getQuerySpec();

				if ($event->isDefaultPrevented()) {
					return $event->getResult() ?? [];
				}

				$result = $this->directusReads()->aggregate($collection, $querySpec);
				$event->setResult($result);

				return $event->getResult() ?? [];
			}

			public function create(string|\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Payload\Node\MutationSpec $spec, array $options = []): array
			{
				$collection = $this->getCollection($collection);
				$options += ['serialize' => false, 'dispatchEvents' => true];
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

				return $this->formatResponseRow($collection, $result, $options) ?? [];
			}

			public function update(
				string|\ON\ORM\Definition\Collection\CollectionInterface $collection,
				\ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity,
				\ON\RestApi\Payload\Node\MutationSpec $spec,
				array $options = []
			): ?array {
				$collection = $this->getCollection($collection);
				$identity = $collection->getPrimaryKey()->getValue($identity);
				$options += ['serialize' => false, 'dispatchEvents' => true];
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

				return $this->formatResponseRow($collection, $result, $options);
			}

			public function delete(
				string|\ON\ORM\Definition\Collection\CollectionInterface $collection,
				\ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity,
				array $options = []
			): bool {
				$collection = $this->getCollection($collection);
				$identity = $collection->getPrimaryKey()->getValue($identity);
				$options += ['serialize' => false, 'dispatchEvents' => true];
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
				return $this->collectionSerializer()->serialize($collection, $phpRow);
			}

			public function unserialize(\ON\ORM\Definition\Collection\CollectionInterface $collection, string|array $payload, bool $partial = false): array
			{
				return $this->collectionSerializer()->unserialize($collection, $payload, $partial);
			}

			private function directusReads(): object
			{
				return new class(
					$this->registry,
					$this->items,
					$this->relationHandlers,
					$this->config
				) {
					private DirectusQueryParser $queryParser;
					private QueryNormalizer $queryNormalizer;
					private SqlQuerySpecCompiler $querySpecCompiler;

					public function __construct(
						private Registry $registry,
						private ItemRepositoryInterface $items,
						private HandlerFactory $handlers,
						private \ON\RestApi\RestApiConfig $config,
					) {
						$this->queryParser = new DirectusQueryParser(defaultLimit: 100, maxLimit: 1000);
						$this->queryNormalizer = new QueryNormalizer();
						$this->querySpecCompiler = new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
					}

					public function list(CollectionInterface $collection, QuerySpec $querySpec): array
					{
						$response = $this->listAction()(
							['collection' => $collection->getName()],
							['query' => $querySpec],
							['serialize' => false, 'dispatchEvents' => false, 'raw' => true]
						);

						return [
							'items' => $response['data'] ?? [],
							'meta' => $response['meta'] ?? [],
						];
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
								['serialize' => false, 'dispatchEvents' => false, 'raw' => true]
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
							['serialize' => false, 'dispatchEvents' => false, 'raw' => true]
						);

						return $response['data'] ?? [];
					}

					private function listAction(): ListAction
					{
						return new ListAction(
							$this->registry,
							$this->items,
							$this->handlers,
							$this->queryParser,
							$this->queryNormalizer,
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
							$this->queryParser,
							$this->queryNormalizer,
							$this->config,
						);
					}
				};
			}

			private function fileUploadEventEmitter(): FileUploadEventEmitter
			{
				return $this->fileUploadEventEmitter ??= new FileUploadEventEmitter($this->registry, $this->eventDispatcher);
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
			->addAction('directus.batch-update', 'PATCH', '{collection}', BatchUpdateAction::class)
			->addAction('directus.delete', 'DELETE', '{collection}/{id}', DeleteAction::class)
			->addAction('directus.batch-delete', 'DELETE', '{collection}', BatchDeleteAction::class);

		$container = new class($registry, $items, $mutationBuilder, $config, $eventDispatcher) implements ContainerInterface {
			private HandlerFactory $handlers;
			private DirectusQueryParser $queryParser;
			private QueryNormalizer $queryNormalizer;

			public function __construct(
				private Registry $registry,
				private ItemRepositoryInterface $items,
				private DirectusMutationBuilder $mutationBuilder,
				private \ON\RestApi\RestApiConfig $config,
				private ?EventDispatcherInterface $eventDispatcher = null,
			) {
				$compiler = new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000);
				$this->handlers = new HandlerFactory(HandlerRegistry::defaults(), $this->items, $compiler);
				$this->queryParser = new DirectusQueryParser(defaultLimit: 100, maxLimit: 1000);
				$this->queryNormalizer = new QueryNormalizer($this->config->get('dynamicVariables', []));
			}

			public function get(string $id): mixed
			{
				return match ($id) {
					ListAction::class => new ListAction($this->registry, $this->items, $this->handlers, $this->queryParser, $this->queryNormalizer, new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000), $this->config, $this->eventDispatcher),
					GetAction::class => new GetAction($this->registry, $this->items, $this->handlers, $this->queryParser, $this->queryNormalizer, $this->config, $this->eventDispatcher),
					CreateAction::class => new CreateAction($this->registry, $this->items, $this->handlers, $this->mutationBuilder, new FileUploadEventEmitter($this->registry, $this->eventDispatcher), $this->config, $this->eventDispatcher),
					UpdateAction::class => new UpdateAction($this->registry, $this->items, $this->handlers, $this->mutationBuilder, new FileUploadEventEmitter($this->registry, $this->eventDispatcher), $this->config, $this->eventDispatcher),
					BatchUpdateAction::class => new BatchUpdateAction($this->registry, $this->items, $this->handlers, $this->mutationBuilder, new FileUploadEventEmitter($this->registry, $this->eventDispatcher), $this->config, $this->eventDispatcher),
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
			static function (string $action, array $params = [], mixed $payload = null) use ($container): mixed {
				return $container->get($action)($params, $payload);
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
		bool $partial = false,
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
			$unserializeWire,
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
		bool $partial = false,
	): MutationSpec {
		return $this->buildMutationSpec($registry, $items, $collection, $input, $mode, $id, $files, $partial);
	}
}
