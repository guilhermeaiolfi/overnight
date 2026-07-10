<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use BadMethodCallException;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use ON\Data\Definition\Registry;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\Repository\ItemRepository;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
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
		$resolver = $this->createItems($registry, $db);
		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items']
		);

		$response = $middleware->process(
			new ServerRequest(uri: '/items/user?fields=id,name', method: 'GET'),
			new class () implements RequestHandlerInterface {
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

	public function testMultipartNestedUploadedFilesArePassedToDirectusAction(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->hasMany('attachments', 'attachment')->innerKey('id')->outerKey('asset_id')->end()
			->end();

		$registry->collection('attachment')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
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
		$runtime = $this->createDataRuntime($db);
		$resolver = new ItemRepository($registry, $runtime, $db->database());
		$this->itemRuntimes[spl_object_id($resolver)] = $runtime;

		RestHooks::for($registry->getCollection('attachment'))
			->on('file.upload', static fn (FileUpload $event) => $event->setStoredValue(501));

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items'],
		);

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
					['file_id' => new class () implements UploadedFileInterface {
						public function getStream(): StreamInterface
						{
							throw new BadMethodCallException('Not needed for this test.');
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
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode(), (string) json_encode($body));
		$this->assertSame('Asset', $body['data']['title']);
		$stmt = $db->database()->query('SELECT asset_id, title, file_id FROM attachment');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row);
		$this->assertSame(1, (int) $row['asset_id']);
		$this->assertSame('Attachment one', $row['title']);
		$this->assertSame(501, (int) $row['file_id']);
	}

	public function testRootAuthorizationCanAllowNestedCreateOperations(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->hasMany('attachments', 'attachment')->innerKey('id')->outerKey('asset_id')->end()
			->end();

		$registry->collection('attachment')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('asset_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
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
				],
				'rows' => [],
			],
		]);
		$resolver = $this->createItems($registry, $db);

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items'],
		);

		$response = $middleware->process(
			(new ServerRequest(uri: '/items/asset', method: 'POST'))
				->withBody($this->streamFromJson([
					'title' => 'Asset',
					'attachments' => [
						['title' => 'Attachment one'],
					],
				])),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode(), (string) json_encode($body));
		$this->assertSame('Asset', $body['data']['title']);
	}

	public function testMultipartNestedUploadedFilesArePassedToDirectusUpdateAction(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->hasMany('attachments', 'attachment')->innerKey('id')->outerKey('asset_id')->end()
			->end();

		$registry->collection('attachment')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('asset_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('file_id', 'upload')->type('upload')->nullable(false)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'title' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'title' => 'Existing asset'],
				],
			],
			'attachment' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'asset_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'file_id' => 'INTEGER',
				],
				'rows' => [
					['id' => 10, 'asset_id' => 1, 'title' => 'Existing attachment', 'file_id' => 100],
				],
			],
		]);
		$runtime = $this->createDataRuntime($db);
		$resolver = new ItemRepository($registry, $runtime, $db->database());
		$this->itemRuntimes[spl_object_id($resolver)] = $runtime;

		RestHooks::for($registry->getCollection('attachment'))
			->on('file.upload', static fn (FileUpload $event) => $event->setStoredValue(501));

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items'],
		);

		$request = (new ServerRequest(
			uri: '/items/asset/1',
			method: 'POST',
			headers: ['Content-Type' => 'multipart/form-data; boundary=test'],
		))
			->withParsedBody([
				'data' => json_encode([
					'title' => 'Updated asset',
					'attachments' => [
						['id' => 10, 'title' => 'Existing attachment'],
						['title' => 'New attachment'],
					],
				], JSON_THROW_ON_ERROR),
			])
			->withUploadedFiles([
				'attachments' => [
					1 => ['file_id' => new class () implements UploadedFileInterface {
						public function getStream(): StreamInterface
						{
							throw new BadMethodCallException('Not needed for this test.');
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
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode(), (string) json_encode($body));
		$this->assertSame('Updated asset', $body['data']['title']);
		$stmt = $db->database()->query('SELECT title, file_id FROM attachment WHERE title = \'New attachment\'');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row);
		$this->assertSame(501, (int) $row['file_id']);
	}

	public function testDirectusFilesEndpointCreatesFileRecordAndDispatchesUploadEvent(): void
	{
		$registry = new Registry();
		$registry->collection('directus_files')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('file', 'upload')->type('upload')->nullable(false)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'directus_files' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'title' => 'TEXT',
					'file' => 'INTEGER',
				],
				'rows' => [],
			],
		]);
		$runtime = $this->createDataRuntime($db);
		$resolver = new ItemRepository($registry, $runtime, $db->database());
		$this->itemRuntimes[spl_object_id($resolver)] = $runtime;

		$uploads = [];
		RestHooks::for($registry->getCollection('directus_files'))
			->on('file.upload', static function (FileUpload $event) use (&$uploads): void {
				$uploads[] = [
					'collection' => $event->getCollection()->getName(),
					'field' => $event->getFieldName(),
					'filename' => $event->getFile()->getClientFilename(),
				];
				$event->setStoredValue(501);
			});

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items'],
		);

		$request = (new ServerRequest(
			uri: '/files',
			method: 'POST',
			headers: ['Content-Type' => 'multipart/form-data; boundary=test'],
		))
			->withParsedBody([
				'data' => json_encode(['title' => 'Uploaded file'], JSON_THROW_ON_ERROR),
			])
			->withUploadedFiles([
				'file' => $this->createUploadedFile('photo.jpg'),
			]);

		$response = $middleware->process(
			$request,
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode(), (string) json_encode($body));
		$this->assertSame('Uploaded file', $body['data']['title']);
		$this->assertSame(501, $body['data']['file']);
		$this->assertSame([[
			'collection' => 'directus_files',
			'field' => 'file',
			'filename' => 'photo.jpg',
		]], $uploads);
		$stmt = $db->database()->query('SELECT title, file FROM directus_files');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row);
		$this->assertSame('Uploaded file', $row['title']);
		$this->assertSame(501, (int) $row['file']);
	}

	public function testAuthorizationErrorsReturnReadableMessagesAndExtensions(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'title' => 'TEXT',
				],
				'rows' => [],
			],
		]);
		$resolver = $this->createItems($registry, $db);

		RestHooks::for($registry->getCollection('asset'))
			->on('create.before', static fn (ItemCreating $event) => $event->forbid(RestApiError::forbidden(
				'Missing permission "create" on resource "module:news".',
				['resource' => 'module:news', 'action' => 'create'],
			)), 100);

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items'],
		);

		$response = $middleware->process(
			(new ServerRequest(uri: '/items/asset', method: 'POST'))
				->withBody($this->streamFromJson(['title' => 'Blocked asset'])),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('Missing permission "create" on resource "module:news".', $body['errors'][0]['message']);
		$this->assertSame('module:news', $body['errors'][0]['extensions']['resource']);
		$this->assertSame('create', $body['errors'][0]['extensions']['action']);
	}

	public function testUnexpectedExceptionsReturnTheOriginalMessage(): void
	{
		$middleware = new RestMiddleware(
			new RestActionRouter([
				['name' => 'boom', 'methods' => ['POST'], 'path' => 'asset', 'action' => 'boom'],
			]),
			static function (): never {
				throw new RuntimeException('Boom failure');
			},
			['endpointUri' => '/items']
		);

		$response = $middleware->process(
			new ServerRequest(uri: '/items/asset', method: 'POST'),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);
		$exceptionClass = (string) ($body['errors'][0]['extensions']['exception'] ?? '');

		$this->assertSame(500, $response->getStatusCode());
		$this->assertSame('Boom failure', $body['errors'][0]['message']);
		$this->assertSame('RuntimeException', basename(str_replace('\\', '/', $exceptionClass)));
	}

	public function testActivationCallbackRunsBeforeMatchedRestRequest(): void
	{
		$activations = 0;
		$middleware = new RestMiddleware(
			new RestActionRouter([
				['name' => 'ok', 'methods' => ['GET'], 'path' => 'asset', 'action' => 'ok'],
			]),
			static function (): array {
				return ['ok' => true];
			},
			['endpointUri' => '/items'],
			null,
			static function () use (&$activations): void {
				$activations++;
			}
		);

		$response = $middleware->process(
			new ServerRequest(uri: '/items/asset', method: 'GET'),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(['ok' => true], $body);
		$this->assertSame(1, $activations);
	}

	private function streamFromJson(array $payload): Stream
	{
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));
		rewind($stream);

		return new Stream($stream);
	}

	private function createUploadedFile(string $filename): UploadedFileInterface
	{
		return new class ($filename) implements UploadedFileInterface {
			public function __construct(private string $filename)
			{
			}

			public function getStream(): StreamInterface
			{
				throw new BadMethodCallException('Not needed for this test.');
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
				return $this->filename;
			}

			public function getClientMediaType(): ?string
			{
				return 'image/jpeg';
			}
		};
	}
}
