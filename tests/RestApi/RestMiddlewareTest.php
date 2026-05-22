<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreating;
use ON\ORM\Definition\Registry;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\RestApiService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class RestMiddlewareTest extends TestCase
{
	use RestApiTestFixtures;

	public function testListFieldsCanComeFromUriQueryString(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createResolver($registry, $db);
		$service = new RestApiService($registry, $resolver);
		$middleware = new RestMiddleware($service, ['endpointUri' => '/items']);

		$response = $middleware->process(
			new ServerRequest(uri: '/items/user?fields=id,name', method: 'GET'),
			new class implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(['id', 'name'], array_keys($body['data'][0]));
		$this->assertArrayNotHasKey('email', $body['data'][0]);
		$this->assertArrayNotHasKey('password', $body['data'][0]);
	}

	public function testMultipartNestedUploadedFilesArePassedToRestApiService(): void
	{
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

		$resolver = new class implements \ON\RestApi\Resolver\DataSourceInterface {
			public array $createCalls = [];
			private array $stored = [];

			public function list(\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Query\Node\QuerySpec $query): array
			{
				return ['items' => [], 'meta' => []];
			}

			public function get(\ON\ORM\Definition\Collection\CollectionInterface $collection, string $id, ?\ON\RestApi\Query\Node\QuerySpec $query = null): ?array
			{
				return $this->stored[$collection->getName()][$id] ?? ['id' => $id];
			}

			public function create(\ON\ORM\Definition\Collection\CollectionInterface $collection, array $input): array
			{
				$record = $input + ['id' => count($this->createCalls) + 1];
				$this->createCalls[] = [
					'collection' => $collection->getName(),
					'input' => $input,
				];
				$this->stored[$collection->getName()][(string) $record['id']] = $record;

				return $record;
			}

			public function update(\ON\ORM\Definition\Collection\CollectionInterface $collection, FilterNode $criteria, array $input): ?array
			{
				$id = $criteria instanceof ComparisonFilter ? (string) $criteria->right->value() : '1';
				$record = ['id' => $id] + $input;
				$this->stored[$collection->getName()][$id] = $record;

				return $record;
			}

			public function delete(\ON\ORM\Definition\Collection\CollectionInterface $collection, FilterNode $criteria): bool
			{
				$id = $criteria instanceof ComparisonFilter ? (string) $criteria->right->value() : '';
				unset($this->stored[$collection->getName()][$id]);

				return true;
			}

			public function aggregate(\ON\ORM\Definition\Collection\CollectionInterface $collection, \ON\RestApi\Query\Node\QuerySpec $query): array
			{
				return [];
			}

			public function transaction(callable $callback): mixed
			{
				return $callback();
			}

			public function clearCache(): void
			{
			}
		};
		$service = new RestApiService(
			$registry,
			$resolver,
			new class implements \Psr\EventDispatcher\EventDispatcherInterface {
				public function dispatch(object $event): object
				{
					if ($event instanceof FileUpload) {
						$event->setStoredValue(501);
					}

					if ($event instanceof ItemCreating) {
						$event->allow();
					}

					return $event;
				}
			}
		);
		$middleware = new RestMiddleware($service, ['endpointUri' => '/items']);

		$request = (new ServerRequest(
			uri: '/items/asset',
			method: 'POST',
			headers: ['Content-Type' => 'multipart/form-data; boundary=test'],
		))
			->withParsedBody([
				'data' => json_encode([
					'title' => 'Asset',
					'attachments' => [
						['title' => 'Attachment one'],
					],
				], JSON_THROW_ON_ERROR),
			])
			->withUploadedFiles([
				'attachments' => [
					['file_id' => new class implements UploadedFileInterface {
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
					}],
				],
			]);

		$response = $middleware->process(
			$request,
			new class implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame('Asset', $body['data']['title']);
		$this->assertCount(2, $resolver->createCalls);
		$this->assertSame(501, $resolver->createCalls[1]['input']['file_id']);
	}
}
