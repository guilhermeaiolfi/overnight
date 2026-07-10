<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class RestMiddlewareValidationTest extends TestCase
{
	use RestApiTestFixtures;

	public function testCreateReturnsConfiguredValidationMessages(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3', [
					'min' => 'Name must be at least :min characters.',
				])
				->end()
			->field('bio', 'string')->type('string')->nullable(true)
				->validation('required')
				->end()
			->end();

		$db = $this->createTestDatabase();
		$resolver = $this->createItems($registry, $db);
		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			$this->createMutationBuilder($registry, $resolver),
			[
				'endpointUri' => '/items',
				'validationMessages' => [
					'rule.required' => 'The :attribute field is required.',
				],
			]
		);

		$response = $middleware->process(
			$this->jsonRequest('POST', '/items/user', ['name' => 'Jo']),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('VALIDATION_ERROR', $body['errors'][0]['extensions']['code']);
		$this->assertSame(
			['Name must be at least 3 characters.'],
			$body['errors'][0]['extensions']['validationErrors']['name']
		);
	}

	public function testPatchOnlyValidatesPresentFields(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->field('bio', 'string')->type('string')->nullable(true)
				->validation('required')
				->end()
			->end();

		$db = $this->createTestDatabase();
		$resolver = $this->createItems($registry, $db);
		$middleware = $this->createRestMiddleware(
			$registry,
			$resolver,
			$this->createMutationBuilder($registry, $resolver),
			['endpointUri' => '/items']
		);

		$response = $middleware->process(
			$this->jsonRequest('PATCH', '/items/user/1', ['name' => 'Al']),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);

		$this->assertSame(400, $response->getStatusCode());
		$this->assertArrayHasKey('name', $body['errors'][0]['extensions']['validationErrors']);
		$this->assertArrayNotHasKey('bio', $body['errors'][0]['extensions']['validationErrors']);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function jsonRequest(string $method, string $uri, array $payload): ServerRequestInterface
	{
		$stream = new Stream('php://temp', 'wb+');
		$stream->write(json_encode($payload, JSON_THROW_ON_ERROR));
		$stream->rewind();

		return (new ServerRequest(
			uri: $uri,
			method: $method,
			headers: ['Content-Type' => 'application/json'],
		))->withBody($stream);
	}
}
