<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Event\ItemUpdate;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Resolver\DataSourceInterface;
use ON\RestApi\RestApiService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class RestApiServiceTest extends TestCase
{
	use RestApiTestFixtures;

	public function testListThrowsForbiddenWhenEventIsNotExplicitlyAllowed(): void
	{
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
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
		$resolver = new ResolverSpy();
		$resolver->listResult = ['items' => [['id' => 1, 'name' => 'John']], 'meta' => []];
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			$resolver,
			function (object $event): object {
				if ($event instanceof ItemList) {
					$event->allow();
				}

				return $event;
			}
		);

		$result = $service->list('user', $this->q($this->createRegistryWithUsers()->getCollection('user')));

		$this->assertSame([['id' => 1, 'name' => 'John']], $result['items']);
		$this->assertSame(1, $resolver->listCalls);
	}

	public function testGetReturnsUnauthenticatedWhenListenerRequiresAuthentication(): void
	{
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			new ResolverSpy(),
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
		$resolver = new ResolverSpy();
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			$resolver,
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
		$this->assertSame(0, $resolver->aggregateCalls);
	}

	public function testFileUploadEventDoesNotNeedAllowWhenParentCreateEventIsAllowed(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('attachment', 'file')->type('file')->nullable(true)->end()
			->end();

		$resolver = new ResolverSpy();
		$resolver->createResult = ['id' => 1, 'attachment' => 'uploads/test.txt'];

		$service = $this->createService(
			$registry,
			$resolver,
			function (object $event): object {
				if ($event instanceof FileUpload) {
					$event->setStoredPath('uploads/test.txt');
				}

				if ($event instanceof ItemCreate) {
					$event->allow();
				}

				return $event;
			}
		);

		$result = $service->create('asset', [], [
			'files' => ['attachment' => new UploadedFileStub()],
		]);

		$this->assertSame(['id' => 1, 'attachment' => 'uploads/test.txt'], $result);
		$this->assertSame(['attachment' => 'uploads/test.txt'], $resolver->lastCreateInput);
	}

	public function testCreateWithExistingPrimaryKeyFailsWithDuplicate(): void
	{
		$resolver = new ResolverSpy();
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			$resolver,
			function (object $event): object {
				if ($event instanceof ItemCreate) {
					$event->allow();
				}

				return $event;
			}
		);

		try {
			$service->create('user', ['id' => 1, 'name' => 'Existing']);
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
			fn (object $event) => $event
		);

		try {
			$service->upsert('user', ['name' => 'Missing ID']);
			$this->fail('Expected missing primary key error to be thrown.');
		} catch (RestApiError $error) {
			$this->assertSame(400, $error->getHttpStatus());
			$this->assertSame('MISSING_PRIMARY_KEY', $error->getErrorCode());
			$this->assertSame('id', $error->getField());
		}
	}

	public function testUpsertDispatchesCreateWhenPrimaryKeyDoesNotExist(): void
	{
		$resolver = new ResolverSpy();
		$resolver->missingIds = ['999'];
		$events = [];
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			$resolver,
			function (object $event) use (&$events): object {
				if ($event instanceof ItemCreate) {
					$event->allow();
					$events[] = 'create';
				}

				return $event;
			}
		);

		$result = $service->upsert('user', ['id' => 999, 'name' => 'New User']);

		$this->assertSame(['create'], $events);
		$this->assertSame(999, $result['id']);
		$this->assertSame('New User', $resolver->lastCreateInput['name']);
	}

	public function testUpsertDispatchesUpdateWhenPrimaryKeyExists(): void
	{
		$resolver = new ResolverSpy();
		$events = [];
		$service = $this->createService(
			$this->createRegistryWithUsers(),
			$resolver,
			function (object $event) use (&$events): object {
				if ($event instanceof ItemUpdate) {
					$event->allow();
					$events[] = 'update';
				}

				return $event;
			}
		);

		$result = $service->upsert('user', ['id' => 1, 'name' => 'Updated User']);

		$this->assertSame(['update'], $events);
		$this->assertSame('1', $result['id']);
		$this->assertSame('Updated User', $result['name']);
	}

	public function testNestedCreateDispatchesEventsForEachNodePath(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$resolver = $this->createResolver($registry, $this->createFullDatabase());
		$paths = [];

		$service = $this->createService(
			$registry,
			$resolver,
			function (object $event) use (&$paths): object {
				if ($event instanceof ItemCreate) {
					$event->allow();
					$paths[] = $event->getPathString();
				}

				return $event;
			}
		);

		$created = $service->create('user', [
			'name' => 'Nested User',
			'email' => 'nested@test.com',
			'posts' => [
				['title' => 'Nested Post', 'content' => 'Content', 'status' => 'published'],
			],
		]);

		$this->assertSame('Nested User', $created['name']);
		$this->assertSame(['', 'posts.0'], $paths);
	}

	public function testNestedUpdateDispatchesEventsForEachNodePath(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$resolver = $this->createResolver($registry, $this->createFullDatabase());
		$paths = [];

		$service = $this->createService(
			$registry,
			$resolver,
			function (object $event) use (&$paths): object {
				if ($event instanceof ItemUpdate) {
					$event->allow();
					$paths[] = $event->getPathString();
				}

				return $event;
			}
		);

		$result = $service->update('post', '1', [
			'title' => 'Updated Post',
			'comments' => [
				['id' => 1, 'body' => 'Updated comment'],
			],
		]);

		$this->assertSame('Updated Post', $result['title']);
		$this->assertSame(['', 'comments.0'], $paths);
	}

	public function testNestedUpdateCreatesMissingChildWithExplicitId(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is required for this test.');
		}

		$registry = new Registry();
		$this->createFullSchema($registry);
		$resolver = $this->createResolver($registry, $this->createFullDatabase());

		$service = $this->createService(
			$registry,
			$resolver,
			function (object $event): object {
				if ($event instanceof ItemCreate || $event instanceof ItemUpdate) {
					$event->allow();
				}

				return $event;
			}
		);

		$service->update('post', '1', [
			'comments' => [
				['id' => 999, 'body' => 'Created with explicit id', 'author' => 'Alice'],
			],
		]);

		$comment = $resolver->get($registry->getCollection('comment'), '999', $this->q($registry->getCollection('comment')));

		$this->assertNotNull($comment);
		$this->assertSame('Created with explicit id', $comment['body']);
		$this->assertSame(1, (int) $comment['post_id']);
	}

	private function createRegistryWithUsers(): Registry
	{
		$registry = new Registry();
		$this->createUserCollection($registry);

		return $registry;
	}

	private function createService(Registry $registry, DataSourceInterface $resolver, callable $listener): RestApiService
	{
		$dispatcher = new class($listener) implements EventDispatcherInterface {
			public function __construct(private $listener)
			{
			}

			public function dispatch(object $event): object
			{
				return ($this->listener)($event);
			}
		};

		return new RestApiService($registry, $resolver, $dispatcher);
	}

	private function q(CollectionInterface $collection, array $params = []): QuerySpec
	{
		return (new DirectusQueryParser())->parse($collection, $params);
	}
}

final class ResolverSpy implements DataSourceInterface
{
	public int $listCalls = 0;
	public int $aggregateCalls = 0;
	public array $listResult = ['items' => [], 'meta' => []];
	public array $aggregateResult = [];
	public array $createResult = [];
	public array $lastCreateInput = [];
	public array $connected = [];
	public array $disconnected = [];
	public array $missingIds = [];

	public function list(CollectionInterface $collection, QuerySpec $query): array
	{
		$this->listCalls++;

		return $this->listResult;
	}

	public function get(CollectionInterface $collection, string $id, ?QuerySpec $query = null): ?array
	{
		if (in_array($id, $this->missingIds, true)) {
			return null;
		}

		return ['id' => $id];
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		$this->lastCreateInput = $input;

		return $this->createResult === [] ? $input + ['id' => 1] : $this->createResult;
	}

	public function update(CollectionInterface $collection, string $id, array $input): ?array
	{
		return ['id' => $id] + $input;
	}

	public function delete(CollectionInterface $collection, string $id): bool
	{
		return true;
	}

	public function aggregate(CollectionInterface $collection, QuerySpec $query): array
	{
		$this->aggregateCalls++;

		return $this->aggregateResult;
	}

	public function transaction(callable $callback): mixed
	{
		return $callback();
	}

	public function connectManyToMany(CollectionInterface $collection, string $parentId, string $relationName, mixed $targetId): void
	{
		$this->connected[] = [$collection->getName(), $parentId, $relationName, $targetId];
	}

	public function disconnectManyToMany(CollectionInterface $collection, string $parentId, string $relationName, mixed $targetId): void
	{
		$this->disconnected[] = [$collection->getName(), $parentId, $relationName, $targetId];
	}

	public function clearCache(): void
	{
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
