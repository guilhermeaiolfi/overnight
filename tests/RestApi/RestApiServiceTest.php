<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Event\RelationConnecting;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryPlannerInterface;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Mapping\CollectionMapper;
use ON\ORM\Typecast\CollectionTypecast;
use ON\RestApi\RestApiService;
use ON\RestApi\Support\PrimaryKeyCriteria;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class RestApiServiceTest extends TestCase
{
	use RestApiTestFixtures;

	private ?EventDispatcherInterface $lastDispatcher = null;

	public function testListThrowsForbiddenWhenEventIsNotExplicitlyAllowed(): void
	{
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
			new QueryPlannerSpy(),
			fn (object $event) => $event
		);

		try {
			$service->list('user', $this->q($this->createRegistryWithUsers()->getCollection('user')));
			$this->fail('Expected forbidden error to be thrown.');
		} catch (RestApiError $error) {
			$this->assertSame(403, $error->getHttpStatus());
			$this->assertSame('FORBIDDEN', $error->getErrorCode());
		}
	}

	public function testListRunsResolverWhenAllowed(): void
	{
		$planner = new QueryPlannerSpy();
		$planner->listResult = ['items' => [['id' => 1, 'name' => 'John']], 'meta' => []];
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
			$planner,
			function (object $event): object {
				if ($event instanceof ItemList) {
					$event->allow();
				}

				return $event;
			}
		);

		$result = $service->list('user', $this->q($this->createRegistryWithUsers()->getCollection('user')));

		$this->assertSame([['id' => 1, 'name' => 'John']], $result['items']);
		$this->assertSame(1, $planner->listCalls);
	}

	public function testGetReturnsUnauthenticatedWhenListenerRequiresAuthentication(): void
	{
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
			new QueryPlannerSpy(),
			function (object $event): object {
				if ($event instanceof ItemGet) {
					$event->requireAuthentication();
				}

				return $event;
			}
		);

		try {
			$service->get('user', '1', $this->q($this->createRegistryWithUsers()->getCollection('user')));
			$this->fail('Expected unauthenticated error to be thrown.');
		} catch (RestApiError $error) {
			$this->assertSame(401, $error->getHttpStatus());
			$this->assertSame('UNAUTHENTICATED', $error->getErrorCode());
		}
	}

	public function testAggregateDispatchesItemListAndSupportsCustomResult(): void
	{
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
			new QueryPlannerSpy(),
			function (object $event): object {
				if ($event instanceof ItemList) {
					$this->assertTrue($event->isAggregate());
					$this->assertSame('count', $event->getAggregate()[0]->responseFunction);
					$this->assertSame('id', $event->getAggregate()[0]->responseField);
					$event->allow();
					$event->setResult([['count' => ['id' => 99]]]);
					$event->preventDefault();
				}

				return $event;
			}
		);

		$result = $service->aggregate('user', $this->q($this->createRegistryWithUsers()->getCollection('user'), [
			'aggregate' => ['count' => 'id'],
		]));

		$this->assertSame([['count' => ['id' => 99]]], $result);
	}

	public function testListPreventDefaultUsesEventOptionsForResponseFormat(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = $this->createTestDatabase();
		$service = $this->createService(
			$registry,
			$this->createItems($registry, $db),
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof ItemList) {
					$event->allow();
					$event->setOptions(['raw' => true]);
					$event->setResult([['id' => 1, 'created_at' => '2025-01-10 10:00:00']]);
					$event->preventDefault();
				}

				return $event;
			}
		);

		$result = $service->list('post', $this->q($registry->getCollection('post')), ['serialize' => true]);

		$this->assertSame('2025-01-10 10:00:00', $result['items'][0]['created_at']);
	}

	public function testFileUploadEventDoesNotNeedAllowWhenParentCreateEventIsAllowed(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('attachment', 'file')->type('file')->nullable(true)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'attachment' => 'TEXT',
				],
				'rows' => [],
			],
		]);
		$resolver = new TrackingItemRepository($registry, $db->database());

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof FileUpload) {
					$event->setStoredPath('uploads/test.txt');
				}

				if ($event instanceof ItemCreating) {
					$event->allow();
				}

				return $event;
			}
		);

		$result = $service->create('asset', $this->ms(
			$registry,
			$resolver,
			'asset',
			[],
			files: ['attachment' => new UploadedFileStub()],
		));

		$this->assertSame('uploads/test.txt', $result['attachment']);
		$this->assertSame(['attachment' => 'uploads/test.txt'], $resolver->lastCreateInput);
	}

	public function testFileUploadEventCanStoreScalarValue(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('attachment_id', 'upload')->type('upload')->nullable(true)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'attachment_id' => 'INTEGER',
				],
				'rows' => [],
			],
		]);
		$resolver = new TrackingItemRepository($registry, $db->database());

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof FileUpload) {
					$event->setStoredValue(42);
				}

				if ($event instanceof ItemCreating) {
					$event->allow();
				}

				return $event;
			}
		);

		$result = $service->create('asset', $this->ms(
			$registry,
			$resolver,
			'asset',
			[],
			files: ['attachment_id' => new UploadedFileStub()],
		));

		$this->assertSame(42, $result['attachment_id']);
		$this->assertSame(['attachment_id' => 42], $resolver->lastCreateInput);
	}

	public function testFileUploadRunsBeforeTransactionStarts(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('attachment_id', 'upload')->type('upload')->nullable(true)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'attachment_id' => 'INTEGER',
				],
				'rows' => [],
			],
		]);
		$resolver = new TrackingItemRepository($registry, $db->database());
		$uploadWasInTransaction = null;

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use ($resolver, &$uploadWasInTransaction): object {
				if ($event instanceof FileUpload) {
					$uploadWasInTransaction = $resolver->inTransaction;
					$event->setStoredValue(42);
				}

				if ($event instanceof ItemCreating) {
					$event->allow();
				}

				return $event;
			}
		);

		$service->create('asset', $this->ms(
			$registry,
			$resolver,
			'asset',
			[],
			files: ['attachment_id' => new UploadedFileStub()],
		));

		$this->assertFalse($uploadWasInTransaction);
		$this->assertSame(1, $resolver->transactionCalls);
	}

	public function testCreatedEventRunsAfterTransactionWithFinalRow(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createUserCollection($registry);
		$db = $this->createTestDatabase();
		$resolver = new TrackingItemRepository($registry, $db->database());
		$createdId = null;
		$createdWasInTransaction = null;

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use ($resolver, &$createdId, &$createdWasInTransaction): object {
				if ($event instanceof ItemCreating) {
					$event->allow();
				}

				if ($event instanceof ItemCreated) {
					$createdWasInTransaction = $resolver->inTransaction;
					$createdId = $event->getState()->resolveValue('id');
				}

				return $event;
			}
		);

		$service->create('user', $this->ms($registry, $resolver, 'user', ['name' => 'Created']));

		$this->assertFalse($createdWasInTransaction);
		$this->assertNotNull($createdId);
	}

	public function testCreateWithExistingPrimaryKeyFailsWithDuplicate(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createUserCollection($registry);
		$db = $this->createTestDatabase();
		$resolver = $this->createItems($registry, $db);
		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof ItemCreating) {
					$event->allow();
				}

				return $event;
			}
		);

		try {
			$service->create('user', $this->ms($registry, $resolver, 'user', ['id' => 1, 'name' => 'Existing']));
			$this->fail('Expected duplicate error to be thrown.');
		} catch (RestApiError $error) {
			$this->assertSame(409, $error->getHttpStatus());
			$this->assertSame('DUPLICATE', $error->getErrorCode());
			$this->assertSame('id', $error->getField());
		}
	}

	public function testUpsertRequiresPrimaryKey(): void
	{
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
			new QueryPlannerSpy(),
			fn (object $event) => $event
		);

		try {
			$service->upsert('user', new MutationSpec(new MutationNodeSpec('user', ['name' => 'Missing ID'])));
			$this->fail('Expected missing primary key error to be thrown.');
		} catch (RestApiError $error) {
			$this->assertSame(400, $error->getHttpStatus());
			$this->assertSame('MISSING_PRIMARY_KEY', $error->getErrorCode());
			$this->assertSame('id', $error->getField());
		}
	}

	public function testUpsertDispatchesCreateWhenPrimaryKeyDoesNotExist(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createUserCollection($registry);
		$db = $this->createTestDatabase();
		$resolver = new TrackingItemRepository($registry, $db->database());
		$events = [];
		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use (&$events): object {
				if ($event instanceof ItemCreating) {
					$event->allow();
					$events[] = 'create';
				}

				return $event;
			}
		);

		$result = $service->upsert('user', $this->ms($registry, $resolver, 'user', ['id' => 999, 'name' => 'New User'], 'upsert'));

		$this->assertSame(['create'], $events);
		$this->assertSame(999, $result['id']);
		$this->assertSame('New User', $resolver->lastCreateInput['name']);
	}

	public function testUpsertDispatchesUpdateWhenPrimaryKeyExists(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createUserCollection($registry);
		$db = $this->createTestDatabase();
		$resolver = $this->createItems($registry, $db);
		$events = [];
		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use (&$events): object {
				if ($event instanceof ItemUpdating) {
					$event->allow();
					$events[] = 'update';
				}

				return $event;
			}
		);

		$result = $service->upsert('user', $this->ms($registry, $resolver, 'user', ['id' => 1, 'name' => 'Updated User'], 'upsert'));

		$this->assertSame(['update'], $events);
		$this->assertSame(1, $result['id']);
		$this->assertSame('Updated User', $result['name']);
	}

	public function testNestedCreateDispatchesEventsForEachNodePath(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createItems($registry, $db);
		$paths = [];

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use (&$paths): object {
				if ($event instanceof ItemCreating) {
					$event->allow();
					$paths[] = $event->getPathString();
				}

				return $event;
			}
		);

		$created = $service->create('user', $this->ms($registry, $resolver, 'user', [
			'name' => 'Nested User',
			'email' => 'nested@test.com',
			'posts' => [
				['title' => 'Nested Post', 'content' => 'Content', 'status' => 'published'],
			],
		]));

		$this->assertSame('Nested User', $created['name']);
		$this->assertSame(['', 'posts.0'], $paths);
	}

	public function testItemDeletingCanChangeOperationToUpdateForSoftDelete(): void
	{
		if (! extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('deleted_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'user' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
					'deleted_at' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'John', 'deleted_at' => null],
				],
			],
		]);
		$resolver = new TrackingItemRepository($registry, $db->database());

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof ItemDeleting) {
					$event->allow();
					$event->getNode()->setOperation('update');
					$event->getState()->setValue('deleted_at', '2026-01-15 10:00:00');
				}

				return $event;
			}
		);

		$deleted = $service->delete('user', '1');

		$this->assertTrue($deleted);
		$this->assertSame([], $resolver->deleteCalls);
		$this->assertCount(1, $resolver->updateCalls);
		$this->assertSame('user', $resolver->updateCalls[0]['collection']);

		$row = $db->database()->select('deleted_at')->from('user')->where('id', 1)->run()->fetch();
		$this->assertSame('2026-01-15 10:00:00', $row['deleted_at'] ?? null);
	}

	public function testAllowNestedCascadesAuthorizationToChildBeforeEvents(): void
	{
		if (! extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createItems($registry, $db);
		$authStates = [];

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use (&$authStates): object {
				if ($event instanceof ItemCreating) {
					if ($event->isRoot()) {
						$event->allow(nested: true);
					}

					$authStates[] = $event->getPathString() . ':' . $event->getAuthState()->name;
				}

				return $event;
			}
		);

		$created = $service->create('user', $this->ms($registry, $resolver, 'user', [
			'name' => 'Nested User',
			'email' => 'nested-auth@test.com',
			'posts' => [
				['title' => 'Nested Post', 'content' => 'Content', 'status' => 'published'],
			],
		]));

		$this->assertSame('Nested User', $created['name']);
		$this->assertSame([':Allowed', 'posts.0:Allowed'], $authStates);
	}

	public function testNestedUpdateDispatchesEventsForEachNodePath(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createItems($registry, $db);
		$paths = [];

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event) use (&$paths): object {
				if ($event instanceof ItemUpdating || $event instanceof ItemDeleting) {
					$event->allow();
					$paths[] = $event->getPathString();
				}

				return $event;
			}
		);

		$result = $service->update('post', '1', $this->ms($registry, $resolver, 'post', [
			'title' => 'Updated Post',
			'comments' => [
				['id' => 1, 'body' => 'Updated comment'],
			],
		], 'update', '1', partial: true));

		$this->assertSame('Updated Post', $result['title']);
		$this->assertSame(['', 'comments.0', 'comments.delete.0'], $paths);
	}

	public function testRelationConnectingListenerCanEnqueueQueueWork(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createItems($registry, $db);

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof ItemUpdating) {
					$event->allow();
				}

				if ($event instanceof RelationConnecting && $event->getRelationName() === 'tags') {
					$targetCollection = $event->getTargetCollection();
					$event->getQueue()->queueUpdate(
						$targetCollection,
						PrimaryKeyCriteria::build(
							$targetCollection,
							PrimaryKeyCriteria::normalize($targetCollection, $event->getTarget())
						),
						new MutationState($targetCollection, ['name' => 'REST-linked'])
					);
				}

				return $event;
			}
		);

		$service->update('post', '2', $this->ms($registry, $resolver, 'post', ['tags' => ['connect' => [3]]], 'update', '2', partial: true));

		$tag = $service->get('tag', '3', null, ['dispatchEvents' => false]);
		$this->assertSame('REST-linked', $tag['name']);

		$stmt = $db->database()->query('SELECT 1 FROM post_tag WHERE post_id = 2 AND tag_id = 3');
		$this->assertNotFalse($stmt->fetch());
		$stmt->close();
	}

	public function testNestedUpdateCreatesMissingChildWithExplicitId(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createItems($registry, $db);
		$planner = $this->createQueryPlanner($registry, $db);

		$service = $this->createService(
			$registry,
			$resolver,
			$planner,
			function (object $event): object {
				if ($event instanceof ItemCreating || $event instanceof ItemUpdating || $event instanceof ItemDeleting) {
					$event->allow();
				}

				return $event;
			}
		);

		$service->update('post', '1', $this->ms($registry, $resolver, 'post', [
			'comments' => [
				['id' => 999, 'body' => 'Created with explicit id', 'author' => 'Alice'],
			],
		], 'update', '1', partial: true));

		$comment = $planner->get($registry->getCollection('comment'), '999', $this->q($registry->getCollection('comment')));

		$this->assertNotNull($comment);
		$this->assertSame('Created with explicit id', $comment['body']);
		$this->assertSame(1, (int) $comment['post_id']);
	}

	public function testNestedCreateHandlesChildFileUploads(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$registry->collection('asset')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->hasMany('attachments', 'attachment')->innerKey('id')->outerKey('asset_id')->end()
			->end();

		$registry->collection('attachment')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('asset_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('file_id', 'upload')->type('upload')->nullable(true)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'title' => 'TEXT',
				],
				'rows' => [],
			],
			'attachment' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'asset_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'file_id' => 'INTEGER',
				],
				'rows' => [],
			],
		]);
		$resolver = new TrackingItemRepository($registry, $db->database());

		$service = $this->createService(
			$registry,
			$resolver,
			$this->createQueryPlanner($registry, $db),
			function (object $event): object {
				if ($event instanceof FileUpload && $event->getCollection()->getName() === 'attachment') {
					$event->setStoredValue(99);
				}

				if ($event instanceof ItemCreating) {
					$event->allow();
				}

				return $event;
			}
		);

		$result = $service->create('asset', $this->ms(
			$registry,
			$resolver,
			'asset',
			[
				'title' => 'Asset',
				'attachments' => [
					['title' => 'Attachment one'],
				],
			],
			files: [
				'attachments' => [
					['file_id' => new UploadedFileStub()],
				],
			],
		));

		$this->assertSame('Asset', $result['title']);
		$this->assertCount(2, $resolver->createCalls);
		$this->assertSame('attachment', $resolver->createCalls[1]['collection']);
		$this->assertSame(99, $resolver->createCalls[1]['input']['file_id']);
	}

	private function createRegistryWithUsers(): Registry
	{
		$registry = new Registry();
		$this->createUserCollection($registry);

		return $registry;
	}

	private function createService(
		Registry $registry,
		ItemRepositoryInterface $items,
		QueryPlannerInterface $queryPlanner,
		callable $listener
	): RestApiService {
		$this->lastDispatcher = $this->createDispatcher($listener);

		return new RestApiService($registry, $items, $queryPlanner, $this->lastDispatcher);
	}

	private function createDispatcher(callable $listener): EventDispatcherInterface
	{
		return new class($listener) implements EventDispatcherInterface {
			public function __construct(private $listener)
			{
			}

			public function dispatch(object $event): object
			{
				return ($this->listener)($event);
			}
		};
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 */
	private function ms(
		Registry $registry,
		ItemRepositoryInterface $items,
		string|CollectionInterface $collection,
		array $input,
		string $mode = 'create',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
		bool $partial = false,
	): MutationSpec {
		return $this->buildMutationSpec(
			$registry,
			$items,
			$collection,
			$input,
			$mode,
			$id,
			$files,
			$partial,
			$this->lastDispatcher,
		);
	}

	private function q(CollectionInterface $collection, array $params = []): QuerySpec
	{
		return (new DirectusQueryParser())->parse($collection, $params);
	}
}

final class QueryPlannerSpy implements QueryPlannerInterface
{
	public int $listCalls = 0;
	public int $getCalls = 0;
	public int $aggregateCalls = 0;
	public array $listResult = ['items' => [], 'meta' => []];
	public array $aggregateResult = [];

	public function list(CollectionInterface $collection, QuerySpec $query): array
	{
		$this->listCalls++;

		return $this->listResult;
	}

	public function get(CollectionInterface $collection, $identity, ?QuerySpec $query = null): ?array
	{
		$this->getCalls++;

		return ['id' => is_string($identity) ? $identity : 1];
	}

	public function aggregate(CollectionInterface $collection, QuerySpec $query): array
	{
		$this->aggregateCalls++;

		return $this->aggregateResult;
	}
}

final class ResolverSpy implements ItemRepositoryInterface
{
	public array $lastCreateInput = [];
	public bool $inTransaction = false;
	public int $transactionCalls = 0;

	public function select(CollectionInterface|string $collection, array $fieldNames = []): \Cycle\Database\Query\SelectQuery
	{
		throw new \BadMethodCallException('Not implemented in spy.');
	}

	public function fetchAll(\Cycle\Database\Query\SelectQuery $query): array
	{
		return [];
	}

	public function fetchOne(\Cycle\Database\Query\SelectQuery $query): ?array
	{
		return null;
	}

	public function count(\Cycle\Database\Query\SelectQuery $query): int
	{
		return 0;
	}

	public function findByIdentity(CollectionInterface $collection, \ON\ORM\Definition\Collection\PrimaryKeyValue|string $identity, bool $typed = true): ?array
	{
		return null;
	}

	public function hydrateRow(CollectionInterface $collection, array $row): array
	{
		return $row;
	}

	public function create(CollectionInterface $collection, array $input): ?array
	{
		$this->lastCreateInput = $input;

		return $input + ['id' => 1];
	}

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array
	{
		$id = $criteria instanceof ComparisonFilter ? $criteria->right->value() : '1';

		return ['id' => $id] + $input;
	}

	public function delete(CollectionInterface $collection, FilterNode $criteria): bool
	{
		return true;
	}

	public function commit(\ON\RestApi\Mutation\MutationQueue $queue, callable $resolve): mixed
	{
		$this->transactionCalls++;
		$this->inTransaction = true;

		try {
			$queue->execute($this);

			return $resolve();
		} finally {
			$this->inTransaction = false;
		}
	}

	public function getDatabase(): \Cycle\Database\DatabaseInterface
	{
		throw new \BadMethodCallException('Not implemented in spy.');
	}
}

final class TrackingItemRepository extends ItemRepository
{
	public array $createCalls = [];
	public array $updateCalls = [];
	public array $deleteCalls = [];
	public array $lastCreateInput = [];
	public bool $inTransaction = false;
	public int $transactionCalls = 0;

	public function __construct(Registry $registry, \Cycle\Database\DatabaseInterface $database)
	{
		parent::__construct(
			$registry,
			$database,
			new CollectionMapper(new CollectionTypecast()),
		);
	}

	public function create(CollectionInterface $collection, array $input): ?array
	{
		$this->lastCreateInput = $input;
		$this->createCalls[] = [
			'collection' => $collection->getName(),
			'input' => $input,
		];

		return parent::create($collection, $input);
	}

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array
	{
		$this->updateCalls[] = [
			'collection' => $collection->getName(),
			'input' => $input,
		];

		return parent::update($collection, $criteria, $input);
	}

	public function delete(CollectionInterface $collection, FilterNode $criteria): bool
	{
		$this->deleteCalls[] = [
			'collection' => $collection->getName(),
		];

		return parent::delete($collection, $criteria);
	}

	public function commit(\ON\RestApi\Mutation\MutationQueue $queue, callable $resolve): mixed
	{
		$this->transactionCalls++;
		$this->inTransaction = true;

		try {
			return parent::commit($queue, $resolve);
		} finally {
			$this->inTransaction = false;
		}
	}
}

final class UploadedFileStub implements UploadedFileInterface
{
	public function getStream(): StreamInterface
	{
		throw new \BadMethodCallException('Not needed for this test.');
	}

	public function moveTo($targetPath): void
	{
	}

	public function getSize(): ?int
	{
		return null;
	}

	public function getError(): int
	{
		return UPLOAD_ERR_OK;
	}

	public function getClientFilename(): ?string
	{
		return 'test.txt';
	}

	public function getClientMediaType(): ?string
	{
		return 'text/plain';
	}
}
