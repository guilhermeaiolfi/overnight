<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\ORM\Definition\Registry;
use ON\RestApi\Middleware\RestMiddleware;
use ON\RestApi\RestApiService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
}
