<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\ORM\Definition\Registry;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHooks;
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
		[, $resolver, $middleware, $db] = $this->createNewsArticleFixture();

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
			new class implements RequestHandlerInterface {
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
		$rows = $db->database()->query('SELECT file_id, news_id FROM news_article_file WHERE news_id = 1 ORDER BY id')->fetchAll();
		$this->assertSame([100, 501], array_map('intval', array_column($rows, 'file_id')));
		$this->assertSame([1, 1], array_map('intval', array_column($rows, 'news_id')));
	}

	public function testPatchUpdateAcceptsExplicitCreateUpdatePayloadWithNestedUpload(): void
	{
		[, $resolver, $middleware, $db] = $this->createNewsArticleFixture();

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
			new class implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode(), (string) json_encode($body));
		$rows = $db->database()->query('SELECT file_id FROM news_article_file WHERE news_id = 1 ORDER BY id')->fetchAll();
		$this->assertSame([100, 501], array_map('intval', array_column($rows, 'file_id')));
	}

	public function testPatchUpdateAcceptsFlatArrayPayloadWithCreateFileUploadKey(): void
	{
		[, $resolver, $middleware, $db] = $this->createNewsArticleFixture();

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
			new class implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(200, $response->getStatusCode(), (string) json_encode($body));
		$rows = $db->database()->query('SELECT file_id FROM news_article_file WHERE news_id = 1 ORDER BY id')->fetchAll();
		$this->assertSame([100, 501], array_map('intval', array_column($rows, 'file_id')));
	}

	private function createUploadedFile(string $filename): UploadedFileInterface
	{
		return new class($filename) implements UploadedFileInterface {
			public function __construct(private string $filename)
			{
			}

			public function getStream(): StreamInterface
			{
				throw new \BadMethodCallException('Not needed for this test.');
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
	 * @return array{0: Registry, 1: ItemRepository, 2: \ON\RestApi\Middleware\RestMiddleware, 3: CycleSqliteTestDatabase}
	 */
	private function createNewsArticleFixture(): array
	{
		$registry = new Registry();
		$registry->collection('news_article')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(false)->end()
			->field('slug', 'string')->type('string')->nullable(false)->end()
			->field('status', 'string')->type('string')->nullable(false)->end()
			->field('modifiedon', 'datetime')->type('datetime')->nullable(false)->end()
			->hasMany('images', 'news_article_file')->innerKey('id')->outerKey('news_id')->end()
			->end();

		$registry->collection('news_article_file')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
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

		$resolver = $this->registerItemsLoader(new class($registry, $db->database()) extends ItemRepository {
			/** @var list<array{collection: string, input: array<string, mixed>}> */
			public array $createCalls = [];

			public function __construct(Registry $registry, \Cycle\Database\DatabaseInterface $database)
			{
				parent::__construct($registry, $database);
			}

			public function create(\ON\ORM\Definition\Collection\CollectionInterface $collection, array $input): ?array
			{
				$this->createCalls[] = [
					'collection' => $collection->getName(),
					'input' => $input,
				];

				return parent::create($collection, $input);
			}
		}, $registry, $db);

		RestHooks::for($registry->getCollection('news_article_file'))
			->on(FileUpload::class, static fn (FileUpload $event) => $event->setStoredValue(501))
			->on(ItemCreating::class, static function (ItemCreating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['createdon'] = $data['createdon'] ?? new \DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			})
			->on(ItemUpdating::class, static function (ItemUpdating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['createdon'] = $data['createdon'] ?? new \DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			});

		RestHooks::for($registry->getCollection('news_article'))
			->on(ItemCreating::class, static function (ItemCreating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['modifiedon'] = $data['modifiedon'] ?? new \DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			})
			->on(ItemUpdating::class, static function (ItemUpdating $event): void {
				$state = $event->getState();
				$data = $state->getData();
				$data['modifiedon'] = $data['modifiedon'] ?? new \DateTimeImmutable('2026-05-29 12:00:00');
				$state->setData($data);
			});

		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			$this->createRecordStoreBuilder($registry, $resolver),
			['endpointUri' => '/items'],
		);

		return [$registry, $resolver, $middleware, $db];
	}
}
