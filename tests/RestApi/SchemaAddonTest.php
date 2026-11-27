<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use DI\Container;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Data\Definition\Registry;
use ON\FS\PathRegistry;
use ON\Init\Init;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Middleware\MiddlewarePriorityPipe;
use ON\Middleware\PipelineExtension;
use ON\RateLimit\InMemoryRateLimiter;
use ON\RateLimit\RateLimiterInterface;
use ON\RequestStack;
use ON\RestApi\Addon\SchemaAddon;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiExtension;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class SchemaAddonTest extends TestCase
{
	use RestApiTestFixtures;

	public function testSchemaIncludesValidationRulesAndMessages(): void
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
			->end();

		$addon = new SchemaAddon($registry);
		$addon->register(['basePath' => '/items']);

		$response = $addon->process(
			new ServerRequest(uri: '/items/_schema/user', method: 'GET'),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);
		$nameField = null;

		foreach ($body['data']['fields'] as $field) {
			if ($field['name'] === 'name') {
				$nameField = $field;

				break;
			}
		}

		$this->assertNotNull($nameField);
		$this->assertSame('required|min:3', $nameField['validation']);
		$this->assertSame(
			['min' => 'Name must be at least :min characters.'],
			$nameField['validationMessages']
		);
	}

	public function testSchemaHandlesMountedAndFullPathsAndKeepsHiddenDefinitionsPrivate(): void
	{
		$registry = new Registry();
		$registry->collection('note')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('draft', 'string')->hidden(true)->end()
			->hasMany('notes', 'private_note')->innerKey('id')->outerKey('note_id')->end()
			->end();
		$registry->collection('private_note')->hidden(true)
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('note_id', 'int')->end()
			->end();

		$addon = new SchemaAddon($registry);
		$addon->register(['apiEndpointPath' => '/api/items']);
		$next = new class () implements RequestHandlerInterface {
			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return new JsonResponse([], 404);
			}
		};

		foreach (['/_schema/note', '/api/items/_schema/note'] as $path) {
			$response = $addon->process(new ServerRequest(uri: $path, method: 'GET'), $next);
			$this->assertSame(200, $response->getStatusCode());
			$body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
			$this->assertArrayNotHasKey('table', $body['data']);
			$this->assertSame(['id'], array_column($body['data']['fields'], 'name'));
			$this->assertSame([], $body['data']['relations']);
		}

		$this->assertSame(404, $addon->process(new ServerRequest(uri: '/_schema-other'), $next)->getStatusCode());
		$this->assertSame(404, $addon->process(new ServerRequest(uri: '/_schema/private_note'), $next)->getStatusCode());

		$addon->register([
			'exposeTableNames' => true,
			'authorize' => static fn (ServerRequestInterface $request): bool => $request->getAttribute('canReadSchema') === true,
		]);
		$request = new ServerRequest(uri: '/_schema/note', method: 'GET');
		$this->assertSame(403, $addon->process($request, $next)->getStatusCode());
		$allowed = $addon->process($request->withAttribute('canReadSchema', true), $next);
		$this->assertSame('note', json_decode((string) $allowed->getBody(), true)['data']['table']);
	}

	public function testMountedSchemaIsRateLimitedBeforeItReturnsData(): void
	{
		$registry = new Registry();
		$middleware = new MiddlewarePriorityPipe(new RequestStack());
		$app = new class ($middleware) extends Application {
			public function __construct(private MiddlewarePriorityPipe $middleware)
			{
			}

			public function hasExtension(string $name_or_class): bool
			{
				return $name_or_class === 'ratelimit';
			}

			public function isDebug(): bool
			{
				return false;
			}

			public function pipe(string $path, MiddlewareInterface $middleware, int $priority): void
			{
				$this->middleware->pipe(\Laminas\Stratigility\path($path, $middleware), $priority);
			}
		};
		$container = new Container();
		$container->set(SchemaAddon::class, new SchemaAddon($registry));
		$container->set(RestApiConfig::class, new RestApiConfig([
			'addons' => [SchemaAddon::class],
			'rateLimit' => 1,
		]));
		$container->set(RateLimiterInterface::class, new InMemoryRateLimiter());
		$container->set(EventDispatcherInterface::class, $this->createStub(EventDispatcherInterface::class));
		$init = new Init();
		$init->registerExtension(new RestApiExtension($app));
		$init->emit(new PipelineReadyEvent(new PipelineExtension($app), $container));

		$this->assertSame(200, $middleware->handle(new ServerRequest(uri: '/items/_schema'))->getStatusCode());
		$this->assertSame(429, $middleware->handle(new ServerRequest(uri: '/items/_schema'))->getStatusCode());
	}

	public function testRepositoryInterfaceResolvesToTheConcreteContainerInstance(): void
	{
		$app = new class () extends Application {
			public function __construct()
			{
				$this->paths = new PathRegistry(['project' => getcwd()], getcwd());
			}
		};
		$extension = new ContainerExtension($app);
		$config = new ContainerConfig(['write_proxies' => false]);
		$init = new Init();
		$init->registerExtension(new RestApiExtension($app));
		$init->emit(new ContainerConfigureEvent($extension, $config));
		$container = $extension->createContainer($config);
		$repository = $this->createItems(new Registry(), new CycleSqliteTestDatabase());
		$container->set(ItemRepository::class, $repository);

		$this->assertSame($repository, $container->get(ItemRepositoryInterface::class));
	}
}
