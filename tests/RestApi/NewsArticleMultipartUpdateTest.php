<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use BadMethodCallException;
use Cycle\Database\DatabaseInterface;
use DateTimeImmutable;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemUpdating;
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
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class NewsArticleMultipartUpdateTest extends TestCase
{
	use RestApiTestFixtures;

	public function testPatchUpdateAcceptsNestedUploadedFiles(): void
	{
		[, , $middleware, $db] = $this->createNewsArticleFixture();

		$payload = [
			'title' => 'Updated article',
			'slug' => 'updated-article',
			'status' => 'draft',
			'images' => [
				['id' => 10, 'sequence' => 0, 'role' => 'image'],
				['sequence' => 1, 'role' => 'image', 'alt_text' => null, 'caption' => null],
			],
		];

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'multipart/form-data; boundary=news-update-boundary'],
		))
			->withParsedBody(['data' => json_encode($payload, JSON_THROW_ON_ERROR)])
			->withUploadedFiles([
				'images' => [
					1 => ['file_id' => $this->createUploadedFile('photo.jpg')],
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
		$this->assertSame('Updated article', $body['data']['title'] ?? null);
		$stmt = $db->database()->query('SELECT file_id, news_id FROM news_article_file WHERE news_id = 1 AND file_id = 501');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row, 'Expected nested uploaded file row to be persisted.');
		$this->assertSame(501, (int) $row['file_id']);
		$this->assertSame(1, (int) $row['news_id']);
	}

	public function testPatchUpdateAcceptsExplicitCreateUpdatePayloadWithNestedUpload(): void
	{
		[, , $middleware, $db] = $this->createNewsArticleFixture();

		$payload = [
			'title' => 'Updated article',
			'slug' => 'updated-article',
			'status' => 'draft',
			'images' => [
				'update' => [
					['id' => 10, 'sequence' => 0, 'role' => 'image'],
				],
				'create' => [
					['sequence' => 1, 'role' => 'image', 'alt_text' => null, 'caption' => null],
				],
			],
		];

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'multipart/form-data; boundary=news-update-boundary'],
		))
			->withParsedBody(['data' => json_encode($payload, JSON_THROW_ON_ERROR)])
			->withUploadedFiles([
				'images' => [
					'create' => [
						0 => ['file_id' => $this->createUploadedFile('photo.jpg')],
					],
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
		$stmt = $db->database()->query('SELECT file_id, news_id FROM news_article_file WHERE news_id = 1 AND file_id = 501');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row, 'Expected nested uploaded file row to be persisted.');
		$this->assertSame(501, (int) $row['file_id']);
		$this->assertSame(1, (int) $row['news_id']);
	}

	public function testPatchUpdateReordersExistingImageAndCreatesNewWithUpload(): void
	{
		[, , $middleware, $db] = $this->createNewsArticleFixture();

		$payload = [
			'title' => 'Reordered article',
			'slug' => 'reordered-article',
			'status' => 'draft',
			'images' => [
				[
					'id' => 10,
					'sequence' => 1,
					'role' => 'image',
					'alt_text' => 'Reordered',
					'caption' => 'Kept',
				],
				[
					'sequence' => 0,
					'role' => 'image',
					'alt_text' => 'New cover',
					'caption' => null,
				],
			],
		];

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'multipart/form-data; boundary=news-reorder-boundary'],
		))
			->withParsedBody(['data' => json_encode($payload, JSON_THROW_ON_ERROR)])
			->withUploadedFiles([
				'images' => [
					1 => ['file_id' => $this->createUploadedFile('cover.jpg')],
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

		$stmt = $db->database()->query(
			'SELECT id, file_id, sequence, alt_text FROM news_article_file WHERE news_id = 1 ORDER BY sequence, id'
		);
		$rows = $stmt->fetchAll();
		$stmt->close();

		$this->assertCount(2, $rows);
		$this->assertSame(0, (int) $rows[0]['sequence']);
		$this->assertSame('New cover', $rows[0]['alt_text']);
		$this->assertSame(501, (int) $rows[0]['file_id']);
		$this->assertSame(1, (int) $rows[1]['sequence']);
		$this->assertSame(10, (int) $rows[1]['id']);
		$this->assertSame('Reordered', $rows[1]['alt_text']);
	}

	public function testPatchUpdateAcceptsFlatArrayPayloadWithCreateFileUploadKey(): void
	{
		[, , $middleware, $db] = $this->createNewsArticleFixture();

		$payload = [
			'title' => 'Updated article',
			'slug' => 'updated-article',
			'status' => 'draft',
			'images' => [
				['id' => 10, 'sequence' => 0, 'role' => 'image'],
				['sequence' => 1, 'role' => 'image', 'alt_text' => null, 'caption' => null],
			],
		];

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'multipart/form-data; boundary=news-update-boundary'],
		))
			->withParsedBody(['data' => json_encode($payload, JSON_THROW_ON_ERROR)])
			->withUploadedFiles([
				'images' => [
					'create' => [
						0 => ['file_id' => $this->createUploadedFile('photo.jpg')],
					],
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
		$stmt = $db->database()->query('SELECT file_id, news_id FROM news_article_file WHERE news_id = 1 AND file_id = 501');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row, 'Expected nested uploaded file row to be persisted.');
		$this->assertSame(501, (int) $row['file_id']);
		$this->assertSame(1, (int) $row['news_id']);
	}


	public function testPatchUpdateKeepingUnchangedImageMetadataDoesNotFail(): void
	{
		[, , $middleware, $db] = $this->createNewsArticleFixture();

		// Admin serialize for unchanged images: id + metadata, no file_id.
		$payload = [
			'title' => 'Edited title only',
			'slug' => 'existing-article',
			'status' => 'draft',
			'images' => [
				[
					'id' => 10,
					'sequence' => 0,
					'role' => 'image',
					'alt_text' => null,
					'caption' => null,
				],
			],
		];

		$stream = new Stream('php://temp', 'wb+');
		$stream->write(json_encode($payload, JSON_THROW_ON_ERROR));
		$stream->rewind();

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'application/json'],
		))->withBody($stream);

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
		$stmt = $db->database()->query('SELECT id, file_id, sequence, role FROM news_article_file WHERE news_id = 1');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertNotFalse($row);
		$this->assertSame(10, (int) $row['id']);
		$this->assertSame(100, (int) $row['file_id']);
		$this->assertSame(0, (int) $row['sequence']);
		$this->assertSame('image', $row['role']);

		$title = $db->database()->query('SELECT title FROM news_article WHERE id = 1')->fetch();
		$this->assertSame('Edited title only', $title['title']);
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
				return 19;
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

	/**
	 * @return array{0: Registry, 1: ItemRepository, 2: RestMiddleware, 3: CycleSqliteTestDatabase}
	 */
	private function createNewsArticleFixture(): array
	{
		$registry = new Registry();
		$registry->collection('news_article')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('title', 'string')->type('string')->nullable(false)->end()
			->field('slug', 'string')->type('string')->nullable(false)->end()
			->field('status', 'string')->type('string')->nullable(false)->end()
			->field('modifiedon', 'datetime')->type('datetime')->nullable(false)->end()
			->hasMany('images', 'news_article_file')->innerKey('id')->outerKey('news_id')->exclusive(true)->end()
			->end();

		$registry->collection('news_article_file')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('news_id', 'int')->type('int')->nullable(false)->end()
			->field('file_id', 'int')->type('int')->nullable(false)->end()
			->field('role', 'string')->type('string')->nullable(true)->end()
			->field('sequence', 'int')->type('int')->nullable(false)->end()
			->field('alt_text', 'string')->type('string')->nullable(true)->end()
			->field('caption', 'text')->type('text')->nullable(true)->end()
			->field('createdon', 'datetime')->type('datetime')->nullable(false)->end()
			->end();

		$db = new CycleSqliteTestDatabase([
			'news_article' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'title' => 'TEXT NOT NULL',
					'slug' => 'TEXT NOT NULL',
					'status' => 'TEXT NOT NULL',
					'modifiedon' => 'TEXT NOT NULL',
				],
				'rows' => [
					['id' => 1, 'title' => 'Existing article', 'slug' => 'existing-article', 'status' => 'draft', 'modifiedon' => '2025-01-01 10:00:00'],
				],
			],
			'news_article_file' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'news_id' => 'INTEGER NOT NULL',
					'file_id' => 'INTEGER NOT NULL',
					'role' => 'TEXT',
					'sequence' => 'INTEGER NOT NULL',
					'alt_text' => 'TEXT',
					'caption' => 'TEXT',
					'createdon' => 'TEXT NOT NULL',
				],
				'rows' => [
					['id' => 10, 'news_id' => 1, 'file_id' => 100, 'role' => 'image', 'sequence' => 0, 'alt_text' => null, 'caption' => null, 'createdon' => '2025-01-01 10:00:00'],
				],
			],
		]);

		$runtime = $this->createDataRuntime($db);
		$resolver = new class ($registry, $runtime, $db->database()) extends ItemRepository {
			/** @var list<array{collection: string, input: array<string, mixed>}> */
			public array $createCalls = [];

			public function __construct(Registry $registry, DataRuntime $runtime, DatabaseInterface $database)
			{
				parent::__construct($registry, $runtime, $database);
			}

			public function create(CollectionInterface $collection, array $input): ?array
			{
				$this->createCalls[] = [
					'collection' => $collection->getName(),
					'input' => $input,
				];

				return parent::create($collection, $input);
			}
		};
		$this->itemRuntimes[spl_object_id($resolver)] = $runtime;

		RestHooks::for($registry->getCollection('news_article_file'))
			->on('file.upload', static fn (FileUpload $event) => $event->setStoredValue(501))
			->on('create.before', static function (ItemCreating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['createdon'] = $data['createdon'] ?? new DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			})
			->on('update.before', static function (ItemUpdating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				if (array_key_exists('sequence', $data)) {
					$data['sequence'] = max(0, (int) $data['sequence']);
				}
				$state->setData($data);
			});

		RestHooks::for($registry->getCollection('news_article'))
			->on('create.before', static function (ItemCreating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['modifiedon'] = $data['modifiedon'] ?? new DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			})
			->on('update.before', static function (ItemUpdating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['modifiedon'] = $data['modifiedon'] ?? new DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			});

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			['endpointUri' => '/items'],
		);

		return [$registry, $resolver, $middleware, $db];
	}
}
