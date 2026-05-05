<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Resolver\RestResolverInterface;
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
			$service->list('user');
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

		$result = $service->list('user');

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
			$service->get('user', '1');
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
					$this->assertSame(['count' => 'id'], $event->getAggregate());
					$event->allow();
					$event->setResult([['count' => ['id' => 99]]]);
					$event->preventDefault();
				}

				return $event;
			}
		);

		$result = $service->aggregate('user', [
			'aggregate' => ['count' => 'id'],
		]);

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

	private function createRegistryWithUsers(): Registry
	{
		$registry = new Registry();
		$this->createUserCollection($registry);

		return $registry;
	}

	private function createService(Registry $registry, ResolverSpy $resolver, callable $listener): RestApiService
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
}

final class ResolverSpy implements RestResolverInterface
{
	public int $listCalls = 0;
	public int $aggregateCalls = 0;
	public array $listResult = ['items' => [], 'meta' => []];
	public array $aggregateResult = [];
	public array $createResult = [];
	public array $lastCreateInput = [];

	public function list(CollectionInterface $collection, array $params = []): array
	{
		$this->listCalls++;

		return $this->listResult;
	}

	public function get(CollectionInterface $collection, string $id, array $params = []): ?array
	{
		return ['id' => $id];
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		$this->lastCreateInput = $input;

		return $this->createResult;
	}

	public function update(CollectionInterface $collection, string $id, array $input): ?array
	{
		return ['id' => $id] + $input;
	}

	public function delete(CollectionInterface $collection, string $id): bool
	{
		return true;
	}

	public function createWithRelations(CollectionInterface $collection, array $input, array $nestedInput): array
	{
		return $this->create($collection, $input);
	}

	public function updateWithRelations(CollectionInterface $collection, string $id, array $input, array $nestedInput): ?array
	{
		return $this->update($collection, $id, $input);
	}

	public function handleM2M(CollectionInterface $collection, string $parentId, M2MRelation $relation, array $operations): void
	{
	}

	public function aggregate(CollectionInterface $collection, array $params = []): array
	{
		$this->aggregateCalls++;

		return $this->aggregateResult;
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
