<?php

declare(strict_types=1);

namespace Tests\ON\Router;

use FilesystemIterator;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\Uri;
use ON\Application;
use ON\Http\InvocationContext;
use ON\Init\Init;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Middleware\MiddlewarePriorityPipe;
use ON\Middleware\PipelineExtension;
use ON\RequestStack;
use ON\Router\Container\RouterFactory;
use ON\Router\Middleware\ImplicitHeadMiddleware;
use ON\Router\Middleware\RouteMiddleware;
use ON\Router\Route;
use ON\Router\Router;
use ON\Router\RouterConfig;
use ON\Router\RouteResult;
use ON\Router\RouterExtension;
use ON\Router\RouterInterface;
use ON\Router\UrlHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RouterRuntimeTest extends TestCase
{
	private ?string $tempDir = null;

	protected function tearDown(): void
	{
		if ($this->tempDir !== null) {
			$this->removeDirectory($this->tempDir);
			$this->tempDir = null;
		}
	}

	public function testRouterStripsBasepathOnlyAsLeadingPrefix(): void
	{
		$config = new RouterConfig([
			'cache_enabled' => false,
			'cache_file' => null,
			'baseHref' => '/app',
		]);
		$config->addRoute('/users/app/settings', 'UserPage::settings', ['GET'], 'user.settings');
		$config->addRoute('/application', 'AppPage::index', ['GET'], 'app.index');
		$config->addRoute('/', 'HomePage::index', ['GET'], 'home');

		$router = new Router($config);

		$matched = $router->match(new ServerRequest([], [], '/app/users/app/settings', 'GET'));
		$this->assertTrue($matched->isSuccess());
		$this->assertSame('user.settings', $matched->getMatchedRouteName());

		$exactBase = $router->match(new ServerRequest([], [], '/app', 'GET'));
		$this->assertTrue($exactBase->isSuccess());
		$this->assertSame('home', $exactBase->getMatchedRouteName());

		$similarPrefix = $router->match(new ServerRequest([], [], '/application', 'GET'));
		$this->assertTrue($similarPrefix->isSuccess());
		$this->assertSame('app.index', $similarPrefix->getMatchedRouteName());
	}

	public function testRouterWritesAndLoadsRouteCacheAtomically(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/overnight-router-cache-' . bin2hex(random_bytes(8));
		$cacheFile = $this->tempDir . '/nested/routes.cache.php';

		$config = new RouterConfig([
			'cache_enabled' => true,
			'cache_file' => $cacheFile,
			'baseHref' => '/',
		]);
		$config->addRoute('/items/{id:\d+}', 'ItemsPage::show', ['GET'], 'items.show');

		$router = new Router($config);
		$result = $router->match(new ServerRequest([], [], '/items/42', 'GET'));

		$this->assertTrue($result->isSuccess());
		$this->assertFileExists($cacheFile);

		// Second router loads directly from cache
		$config2 = new RouterConfig([
			'cache_enabled' => true,
			'cache_file' => $cacheFile,
			'baseHref' => '/',
		]);
		$config2->addRoute('/items/{id:\d+}', 'ItemsPage::show', ['GET'], 'items.show');

		$router2 = new Router($config2);
		$result2 = $router2->match(new ServerRequest([], [], '/items/99', 'GET'));

		$this->assertTrue($result2->isSuccess());
		$this->assertSame(['id' => '99'], $result2->getMatchedParams());
	}

	public function testRouterFactoryHandlesMissingRouterConfig(): void
	{
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with(RouterConfig::class)->willReturn(false);

		$router = (new RouterFactory())($container);
		$this->assertInstanceOf(Router::class, $router);
	}

	public function testRouteMiddlewareAttachesFailedResultOn405ButPreserves404PassThrough(): void
	{
		$config = new RouterConfig([
			'cache_enabled' => false,
			'cache_file' => null,
			'baseHref' => '/',
		]);
		$config->addRoute('/only-get', 'Page::show', ['GET'], 'only.get');

		$router = new Router($config);
		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$container = $this->createMock(ContainerInterface::class);

		$middleware = new RouteMiddleware($router, $app, $container);

		// 405 Method Not Allowed attaches failed RouteResult
		$captured405 = null;
		$handler405 = $this->createCapturingHandler($captured405);
		$middleware->process(new ServerRequest([], [], '/only-get', 'POST'), $handler405);

		$result405 = $captured405->getAttribute(RouteResult::class);
		$this->assertInstanceOf(RouteResult::class, $result405);
		$this->assertTrue($result405->isFailure());
		$this->assertTrue($result405->isMethodFailure());
		$this->assertSame(['GET'], $result405->getAllowedMethods());

		// 404 Not Found preserves pass-through without attaching RouteResult
		$captured404 = null;
		$handler404 = $this->createCapturingHandler($captured404);
		$middleware->process(new ServerRequest([], [], '/unknown', 'GET'), $handler404);

		$this->assertNull($captured404->getAttribute(RouteResult::class));
	}

	public function testRouterPipelineRespondsToMethodFailureAndPassesThroughMissingPaths(): void
	{
		$config = new RouterConfig(['cache_enabled' => false, 'baseHref' => '/']);
		$config->addRoute('/read-only', 'Pages::read', ['GET']);
		$router = new Router($config);
		$container = $this->createStub(ContainerInterface::class);
		$pipe = new MiddlewarePriorityPipe(new RequestStack());

		$app = new class ($router, $container, $pipe) extends Application {
			public function __construct(
				private Router $router,
				private ContainerInterface $container,
				private MiddlewarePriorityPipe $pipe,
			) {
				$this->init = new Init();
				$this->pipeline = new PipelineExtension($this);
			}

			public function __call($name, $args)
			{
				[, $middleware, $priority] = $args;
				$instance = $middleware === RouteMiddleware::class
					? new RouteMiddleware($this->router, $this, $this->container)
					: new $middleware();
				$this->pipe->pipe($instance, $priority);
			}
		};
		$init = new Init();
		(new RouterExtension($app))->register($init);
		$init->emit(new PipelineReadyEvent($app->pipeline, $container));

		$handler = $this->createStub(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn(new TextResponse('fallback', 404));

		$wrongMethod = $pipe->process(new ServerRequest([], [], '/read-only', 'POST'), $handler);
		$this->assertSame(405, $wrongMethod->getStatusCode());
		$this->assertSame('GET', $wrongMethod->getHeaderLine('Allow'));

		$unknownPath = $pipe->process(new ServerRequest([], [], '/unknown', 'POST'), $handler);
		$this->assertSame(404, $unknownPath->getStatusCode());
		$this->assertSame('fallback', (string) $unknownPath->getBody());
	}

	public function testRouteMiddlewareCastsFalseBooleansAndGuardsNonScalarBuiltins(): void
	{
		$target = new class () {
			public function action(bool $active, bool $enabled, mixed $raw, iterable $items = []): void
			{
			}
		};

		$route = new Route('/check/{active}/{enabled}/{raw}', get_class($target) . '::action');
		$routeResult = RouteResult::fromRoute($route, [
			'active' => 'false',
			'enabled' => '0',
			'raw' => 'hello',
		]);

		$router = $this->createMock(RouterInterface::class);
		$router->method('match')->willReturn($routeResult);

		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(get_class($target))->willReturn($target);

		$middleware = new RouteMiddleware($router, $app, $container);
		$captured = null;
		$middleware->process(new ServerRequest(), $this->createCapturingHandler($captured));

		$context = InvocationContext::fromRequest($captured);
		$args = $context->applyToArgs([]);

		$this->assertFalse($args['active']);
		$this->assertFalse($args['enabled']);
		$this->assertSame('hello', $args['raw']);
	}

	public function testImplicitHeadMiddlewareSupportsBothCallableAndStreamFactoryInterface(): void
	{
		$route = new Route('/ping', 'Ping::index', ['GET']);
		$getMatch = RouteResult::fromRoute($route, ['id' => '7']);
		$headFailure = RouteResult::fromRouteFailure(['GET']);

		$router = $this->createMock(RouterInterface::class);
		$router->method('match')->willReturn($getMatch);

		$handler = new class () implements RequestHandlerInterface {
			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return new TextResponse('non-empty body');
			}
		};

		$request = (new ServerRequest([], [], '/ping', 'HEAD'))
			->withAttribute(RouteResult::class, $headFailure);

		// Callable stream factory
		$callableMiddleware = new ImplicitHeadMiddleware(
			$router,
			fn (): StreamInterface => new Stream('php://temp', 'wb+')
		);
		$response1 = $callableMiddleware->process($request, $handler);
		$this->assertSame('', (string) $response1->getBody());

		// StreamFactoryInterface
		$factoryMiddleware = new ImplicitHeadMiddleware($router, new StreamFactory());
		$response2 = $factoryMiddleware->process($request, $handler);
		$this->assertSame('', (string) $response2->getBody());
	}

	public function testUrlHelperAvoidsDoubleSlashAndPort80Bugs(): void
	{
		$router = $this->createMock(RouterInterface::class);
		$router->method('getBasePath')->willReturn('/');
		$router->method('generateUri')->willReturn('/users/profile');
		$router->method('match')->willReturn(RouteResult::fromRouteFailure(null));

		$request = new ServerRequest([], [], new Uri('https://example.com/current'));
		$helper = new UrlHelper($router, $request);

		// Relative URL with "/" basepath must NOT become "//users/profile" or include ":80"
		$this->assertSame('/users/profile', $helper->gen('users.profile'));
		$this->assertSame('/users/profile?page=2#top', $helper->gen('users.profile', ['page' => 2], ['fragment' => 'top']));

		// Absolute HTTPS URL must NOT force :80
		$this->assertSame(
			'https://example.com/users/profile',
			$helper->gen('users.profile', [], ['absolute' => true, 'scheme' => 'https'])
		);

		// Explicit custom port is preserved
		$this->assertSame(
			'https://example.com:8443/users/profile',
			$helper->gen('users.profile', [], ['absolute' => true, 'scheme' => 'https', 'port' => 8443])
		);
	}

	public function testRouterExtensionRegistersDeleteMacro(): void
	{
		$registered = [];
		$app = $this->createMock(Application::class);
		$app->method('isCli')->willReturn(false);
		$app->method('registerMethod')->willReturnCallback(function (string $name, callable $cb) use (&$registered): void {
			$registered[] = $name;
		});

		$ext = new RouterExtension($app);
		$ext->register(new Init());

		$this->assertContains('delete', $registered);
		$this->assertContains('get', $registered);
		$this->assertContains('post', $registered);
		$this->assertContains('put', $registered);
		$this->assertContains('patch', $registered);
	}

	private function createCapturingHandler(?ServerRequestInterface &$captured): RequestHandlerInterface
	{
		return new class ($captured) implements RequestHandlerInterface {
			public function __construct(private ?ServerRequestInterface &$captured)
			{
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				$this->captured = $request;

				return new TextResponse('ok');
			}
		};
	}

	private function removeDirectory(string $dir): void
	{
		if (! is_dir($dir)) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($dir);
	}
}
