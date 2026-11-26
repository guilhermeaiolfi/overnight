<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use DateTimeImmutable;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use ON\Data\Definition\Registry;
use function ON\Data\Query\x;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Middleware\RestMiddleware;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class RestApiMutationSecurityTest extends TestCase
{
	use RestApiTestFixtures;

	public function testDeniedWritesDoNotRunFrameworkUploadOrPreconditionChecks(): void
	{
		[$registry, $database] = $this->assetFixtures();
		$uploads = 0;
		RestHooks::for($registry->getCollection('asset'))
			->on('create.before', static fn (ItemCreating $event) => $event->forbid(), 100)
			->on('update.before', static fn (ItemUpdating $event) => $event->forbid(), 100)
			->on('file.upload', static function () use (&$uploads): void {
				++$uploads;
			});
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));

		$upload = new UploadedFile((new StreamFactory())->createStream('document'), 8, UPLOAD_ERR_OK, 'document.txt');
		$request = (new ServerRequest(uri: '/items/asset', method: 'POST', headers: [
			'Content-Type' => 'multipart/form-data; boundary=sample',
		]))->withParsedBody(['data' => '{"title":"Document"}'])->withUploadedFiles(['file_id' => $upload]);
		$this->assertSame(403, $this->respond($middleware, $request)->getStatusCode());
		$this->assertSame(0, $uploads);

		$request = new ServerRequest(uri: '/items/asset/27', method: 'PATCH', headers: ['If-Match' => 'W/"previous"']);
		$this->assertSame(403, $this->respond($middleware, $request)->getStatusCode());
	}

	public function testRawMultipartPatchPreservesJsonFieldsAndUploadedFiles(): void
	{
		[$registry, $database] = $this->assetFixtures();
		RestHooks::for($registry->getCollection('asset'))
			->on('file.upload', static fn (FileUpload $event) => $event->setStoredValue(42));
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));
		$multipart = "--sample\r\n"
			. "Content-Disposition: form-data; name=\"data\"\r\n\r\n"
			. "{\"title\":\"Updated\",\"id\":99}\r\n"
			. "--sample\r\n"
			. "Content-Disposition: form-data; name=\"file_id\"; filename=\"document.txt\"\r\n"
			. "Content-Type: text/plain\r\n\r\n"
			. "document\r\n--sample--\r\n";
		$request = (new ServerRequest(uri: '/items/asset/1', method: 'PATCH', headers: [
			'Content-Type' => 'multipart/form-data; boundary=sample',
		]))->withBody((new StreamFactory())->createStream($multipart));

		$response = $this->respond($middleware, $request);

		$this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		$this->assertSame(['id' => 1, 'title' => 'Updated', 'file_id' => 42], $this->bodyOf($response)['data']);
	}

	public function testStandaloneMiddlewareChecksItsPathAndDoesNotReadGetBodies(): void
	{
		[$registry, $database] = $this->assetFixtures();
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));
		$foreign = new ServerRequest(uri: '/items-archive/asset', method: 'GET');
		$this->assertSame(404, $this->respond($middleware, $foreign)->getStatusCode());

		$request = (new ServerRequest(uri: '/items/asset/1', method: 'GET'))
			->withBody((new StreamFactory())->createStream('unused'));
		$this->assertSame(200, $this->respond($middleware, $request)->getStatusCode());

		$mounted = $this->createRestMiddleware(
			$registry,
			$this->createItems($registry, $database),
			['endpointUri' => '/asset', 'pathPrefixStripped' => true],
		);
		$this->assertSame(200, $this->respond($mounted, new ServerRequest(uri: '/asset/1'))->getStatusCode());
	}

	public function testNestedCreatesValidateAndExposePersistedKeyToAfterHooks(): void
	{
		[$registry, $database] = $this->assetFixtures();
		$seen = [];
		RestHooks::for($registry->getCollection('attachment'))
			->on('create.before', static fn (ItemCreating $event) => $event->getState()->setMetadata('origin', 'upload'))
			->on('create.after', static function (ItemCreated $event) use (&$seen): void {
				$state = $event->getState();
				$seen[] = [$state->isReady(), $state->getKey()?->getValue(), $event->getRow()['title'], $state->getMetadata('origin')];
			});
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));

		$invalid = $this->sendJson($middleware, 'POST', '/items/asset', ['title' => 'Owner', 'attachments' => [['title' => '']]]);
		$this->assertSame(400, $invalid->getStatusCode(), (string) $invalid->getBody());
		$this->assertSame('attachments.0.title', $this->bodyOf($invalid)['errors'][0]['extensions']['field']);
		$this->assertSame([], $seen);

		$valid = $this->sendJson($middleware, 'POST', '/items/asset', [
			'title' => 'Owner',
			'attachments' => [['title' => 'Attachment']],
		]);
		$this->assertSame(200, $valid->getStatusCode(), (string) $valid->getBody());
		$this->assertSame([[true, 1, 'Attachment', 'upload']], $seen);

		$partial = $this->sendJson($middleware, 'PATCH', '/items/asset/2', ['attachments' => [['id' => 1]]]);
		$this->assertSame(200, $partial->getStatusCode(), (string) $partial->getBody());
	}

	public function testRootHooksKeepTypedStateAndMetadataAcrossPersistence(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$database = $this->createFullDatabase();
		$beforeState = null;
		RestHooks::for($registry->getCollection('post'))
			->on('create.before', function (ItemCreating $event) use (&$beforeState): void {
				$beforeState = $event->getState();
				$this->assertInstanceOf(DateTimeImmutable::class, $beforeState->getValue('created_at'));
				$beforeState->setMetadata('origin', 'api');
			})
			->on('create.after', function (ItemCreated $event) use (&$beforeState): void {
				$this->assertSame($beforeState, $event->getState());
				$this->assertSame('api', $event->getState()->getMetadata('origin'));
			});
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));

		$response = $this->sendJson($middleware, 'POST', '/items/post', [
			'user_id' => 1,
			'title' => 'Typed value',
			'created_at' => '2025-05-03T17:30:00+00:00',
		]);

		$this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		$this->assertNotNull($beforeState);
	}

	public function testBatchLimitsAndElementTypesAreCheckedBeforeWrites(): void
	{
		[$registry, $database] = $this->assetFixtures();
		$middleware = $this->createRestMiddleware(
			$registry,
			$this->createItems($registry, $database),
			['endpointUri' => '/items', 'maxBatchSize' => 2],
		);
		$cases = [
			['POST', [['title' => 'First'], 'not an item'], 'INVALID_PAYLOAD'],
			['PATCH', [['id' => 1], 'not an item'], 'INVALID_PAYLOAD'],
			['DELETE', [true], 'INVALID_PRIMARY_KEY'],
			['POST', [['title' => 'First'], ['title' => 'Second'], ['title' => 'Third']], 'BATCH_LIMIT_EXCEEDED'],
		];
		foreach ($cases as [$method, $payload, $code]) {
			$response = $this->sendJson($middleware, $method, '/items/asset', $payload);
			$this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
			$this->assertSame($code, $this->bodyOf($response)['errors'][0]['extensions']['code']);
		}

		$response = $this->respond($middleware, new ServerRequest(uri: '/items/asset'));
		$this->assertSame([1], array_column($this->bodyOf($response)['data'], 'id'));
	}

	public function testDuplicateDatabaseConstraintBecomesConflict(): void
	{
		[$registry, $database] = $this->assetFixtures();
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));

		$response = $this->sendJson($middleware, 'POST', '/items/asset', ['title' => 'Original']);

		$this->assertSame(409, $response->getStatusCode(), (string) $response->getBody());
		$error = $this->bodyOf($response)['errors'][0];
		$this->assertSame('DUPLICATE', $error['extensions']['code']);
		$this->assertSame('title', $error['extensions']['field']);
	}

	public function testMalformedCompositeKeyIsABadRequest(): void
	{
		$registry = new Registry();
		$registry->collection('asset')
			->primaryKey('account_id', 'id')
			->field('account_id', 'int')->end()
			->field('id', 'int')->end()
			->end();
		$database = new CycleSqliteTestDatabase();
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));

		$response = $this->respond($middleware, new ServerRequest(uri: '/items/asset/incomplete'));
		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('INVALID_PRIMARY_KEY', $this->bodyOf($response)['errors'][0]['extensions']['code']);
	}

	public function testListTotalCountPreservesHookScopeAndNestedAliasesKeepTheirParents(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$database = $this->createFullDatabase();
		RestHooks::for($registry->getCollection('user'))
			->on('list', static function (ItemList $event): void {
				$query = $event->getQuery()->copy();
				$query->where(x()->lte($query->field('id'), 2));
				$event->setQuery($query);
				$event->allow();
			});
		$middleware = $this->createRestMiddleware($registry, $this->createItems($registry, $database));
		$request = (new ServerRequest(uri: '/items/user'))->withQueryParams([
			'fields' => 'id,articles.id,articles.replies.id',
			'alias' => ['articles' => 'posts', 'articles.replies' => 'comments'],
			'filter' => ['id' => ['_eq' => 1]],
			'meta' => 'total_count,filter_count',
		]);

		$response = $this->respond($middleware, $request);
		$this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		$body = $this->bodyOf($response);
		$this->assertSame(['total_count' => 2, 'filter_count' => 1], $body['meta']);
		$this->assertSame([1], array_column($body['data'], 'id'));
		$this->assertNotEmpty($body['data'][0]['articles']);
		$articles = $body['data'][0]['articles'];
		$this->assertArrayHasKey('replies', $articles[0]);
		$this->assertArrayNotHasKey('comments', $articles[0]);

		$item = $request->withUri($request->getUri()->withPath('/items/user/1'));
		$single = $this->respond($middleware, $item);
		$this->assertSame(200, $single->getStatusCode(), (string) $single->getBody());
		$this->assertSame($articles, $this->bodyOf($single)['data']['articles']);
	}

	private function assetFixtures(): array
	{
		$registry = new Registry();
		$registry->collection('asset')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('title', 'string')->end()
			->field('file_id', 'upload')->end()
			->hasMany('attachments', 'attachment')->innerKey('id')->outerKey('asset_id')->end()
			->end();
		$registry->collection('attachment')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('asset_id', 'int')->end()
			->field('title', 'string')->validation('required')->end()
			->end();

		$database = new CycleSqliteTestDatabase([
			'asset' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'title' => 'TEXT NOT NULL UNIQUE', 'file_id' => 'INTEGER'],
				'rows' => [['id' => 1, 'title' => 'Original', 'file_id' => null]],
			],
			'attachment' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'asset_id' => 'INTEGER NOT NULL', 'title' => 'TEXT NOT NULL'],
				'rows' => [],
			],
		]);

		return [$registry, $database];
	}

	private function respond(RestMiddleware $middleware, ServerRequestInterface $request): ResponseInterface
	{
		return $middleware->process($request, new class () implements RequestHandlerInterface {
			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return new JsonResponse(['miss' => true], 404);
			}
		});
	}

	private function sendJson(RestMiddleware $middleware, string $method, string $path, array $data): ResponseInterface
	{
		$request = (new ServerRequest(uri: $path, method: $method))
			->withBody((new StreamFactory())->createStream(json_encode($data, JSON_THROW_ON_ERROR)));

		return $this->respond($middleware, $request);
	}

	private function bodyOf(ResponseInterface $response): array
	{
		return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
	}
}
