<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use ON\GraphQL\Event\QueryComplete;
use ON\GraphQL\Middleware\GraphQLMiddleware;
use ON\GraphQL\Type\UploadType;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class GraphQLMiddlewareTest extends TestCase
{
	public function testGetUsesTheSelectedOperationAndRequiresPostForAMutation(): void
	{
		$middleware = new GraphQLMiddleware($this->createSchema());
		$request = (new ServerRequest())->withMethod('GET')->withQueryParams([
			'query' => 'query Read { status } mutation Write { change }',
			'operationName' => 'Read',
		]);

		$this->assertSame(['data' => ['status' => 'ready']], $this->json($this->process($middleware, $request)));

		$response = $this->process($middleware, $request->withQueryParams([
			...$request->getQueryParams(),
			'operationName' => 'Write',
		]));

		$this->assertSame(405, $response->getStatusCode());
		$this->assertSame('POST', $response->getHeaderLine('Allow'));
	}

	public function testSingleJsonRequestReadsItsStreamOnce(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->expects($this->once())->method('__toString')->willReturn('{"query":"{ status }"}');

		$request = (new ServerRequest())->withMethod('POST')
			->withHeader('Content-Type', 'application/json')
			->withBody($stream);

		$this->assertSame(
			['data' => ['status' => 'ready']],
			$this->json($this->process(new GraphQLMiddleware($this->createSchema()), $request))
		);
	}

	public function testJsonBatchEnforcesTheConfiguredOperationCount(): void
	{
		$operation = ['query' => '{ status }'];
		$middleware = new GraphQLMiddleware($this->createSchema(), maxBatchSize: 2);

		$response = $this->process($middleware, $this->jsonRequest([$operation, $operation]));
		$this->assertSame(200, $response->getStatusCode());
		$this->assertCount(2, $this->json($response));

		$response = $this->process($middleware, $this->jsonRequest([$operation, $operation, $operation]));
		$this->assertSame(400, $response->getStatusCode());
	}

	public function testRejectsInvalidOperationNamesAcrossRequestFormats(): void
	{
		$middleware = new GraphQLMiddleware($this->createSchema());
		$get = (new ServerRequest())->withMethod('GET')->withQueryParams([
			'query' => 'query Read { status }',
			'operationName' => ['Read'],
		]);
		$json = $this->jsonRequest([
			'query' => 'query Read { status }',
			'operationName' => 123,
		]);
		$multipart = (new ServerRequest())->withMethod('POST')
			->withHeader('Content-Type', 'multipart/form-data')
			->withParsedBody([
				'operations' => '{"query":"query Read { status }","operationName":{"label":"Read"}}',
			]);

		foreach ([$get, $json, $multipart] as $request) {
			$response = $this->process($middleware, $request);
			$this->assertSame(400, $response->getStatusCode());
			$result = $this->json($response);
			$this->assertArrayNotHasKey('data', $result);
			$this->assertStringContainsString('operationName', $result['errors'][0]['message']);
		}
	}

	public function testInvalidOperationNameOnlyRejectsItsBatchEntry(): void
	{
		$middleware = new GraphQLMiddleware($this->createSchema());
		$operation = ['query' => 'query Read { status }'];
		$response = $this->process($middleware, $this->jsonRequest([
			$operation + ['operationName' => false],
			$operation + ['operationName' => ''],
			$operation + ['operationName' => 'Read'],
			$operation + ['operationName' => null],
		]));
		$this->assertSame(200, $response->getStatusCode());
		$results = $this->json($response);

		foreach (array_slice($results, 0, 2) as $result) {
			$this->assertArrayNotHasKey('data', $result);
			$this->assertStringContainsString('operationName', $result['errors'][0]['message']);
		}

		$this->assertSame(['data' => ['status' => 'ready']], $results[2]);
		$this->assertSame(['data' => ['status' => 'ready']], $results[3]);
	}

	public function testDirectMiddlewareRequiresAnIntrospectionOptIn(): void
	{
		$request = $this->jsonRequest(['query' => '{ __schema { queryType { name } } }']);

		$response = $this->json($this->process(new GraphQLMiddleware($this->createSchema()), $request));
		$this->assertArrayHasKey('errors', $response);

		$response = $this->json($this->process(new GraphQLMiddleware($this->createSchema(), allowIntrospection: true), $request));
		$this->assertSame(['name' => 'Query'], $response['data']['__schema']['queryType']);
	}

	public function testMultipartRejectsMalformedMetadata(): void
	{
		$request = (new ServerRequest())->withMethod('POST')
			->withHeader('Content-Type', 'multipart/form-data');

		$middleware = new GraphQLMiddleware($this->createSchema());
		$response = $this->process($middleware, $request->withParsedBody(['operations' => '{']));
		$this->assertSame(400, $response->getStatusCode());

		$response = $this->process($middleware, $request->withParsedBody([
			'operations' => '{"query":"{ status }"}',
			'map' => '{',
		]));
		$this->assertSame(400, $response->getStatusCode());
	}

	public function testCompletionIsDispatchedWhenQueryExecutionThrows(): void
	{
		$dispatcher = $this->createMock(EventDispatcherInterface::class);
		$dispatcher->expects($this->once())
			->method('dispatch')
			->with($this->callback(static fn (object $event): bool => $event instanceof QueryComplete && $event->getResult() === []))
			->willReturnArgument(0);
		$schema = $this->createMock(Schema::class);
		$schema->method('getQueryType')->willThrowException(new RuntimeException('schema unavailable'));

		$this->expectExceptionMessage('schema unavailable');
		$this->process(new GraphQLMiddleware($schema, eventDispatcher: $dispatcher), $this->jsonRequest(['query' => '{ status }']));
	}

	public function testMultipartPassesFilesIntoDeclaredVariables(): void
	{
		$request = (new ServerRequest())->withMethod('POST')
			->withHeader('Content-Type', 'multipart/form-data; boundary=example')
			->withParsedBody([
				'operations' => '{"query":"mutation ($file: Upload!) { filename(file: $file) }","variables":{"file":null}}',
				'map' => '{"0":["variables.file"]}',
			])
			->withUploadedFiles([
				new UploadedFile(new Stream('php://temp', 'w+b'), 0, UPLOAD_ERR_OK, 'report.txt'),
			]);

		$response = $this->process(new GraphQLMiddleware($this->createSchema()), $request);
		$this->assertSame(['data' => ['filename' => 'report.txt']], $this->json($response));
	}

	private function createSchema(): Schema
	{
		return new Schema([
			'query' => new ObjectType([
				'name' => 'Query',
				'fields' => ['status' => ['type' => Type::string(), 'resolve' => static fn () => 'ready']],
			]),
			'mutation' => new ObjectType([
				'name' => 'Mutation',
				'fields' => [
					'change' => ['type' => Type::boolean(), 'resolve' => static fn () => true],
					'filename' => [
						'type' => Type::string(),
						'args' => ['file' => Type::nonNull(new UploadType())],
						'resolve' => static fn ($root, array $args): ?string => $args['file']->getClientFilename(),
					],
				],
			]),
		]);
	}

	private function jsonRequest(array $payload): ServerRequestInterface
	{
		$stream = new Stream('php://temp', 'w+b');
		$stream->write(json_encode($payload, JSON_THROW_ON_ERROR));

		return (new ServerRequest())->withMethod('POST')->withHeader('Content-Type', 'application/json')->withBody($stream);
	}

	private function process(GraphQLMiddleware $middleware, ServerRequestInterface $request): ResponseInterface
	{
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		return $middleware->process($request, $handler);
	}

	private function json(ResponseInterface $response): array
	{
		return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
	}
}
