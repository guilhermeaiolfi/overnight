<?php

declare(strict_types=1);

namespace Tests\ON\Clockwork;

use Clockwork\Request\Request as ClockworkRequest;
use Clockwork\Support\Vanilla\Clockwork;
use FilesystemIterator;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Monolog\Logger;
use ON\Application;
use ON\Clockwork\ClockworkExtension;
use ON\Clockwork\DataSource\PsrLoggerDataSource;
use ON\Clockwork\Middleware\ClockworkMiddleware;
use ON\Container\ContainerExtension;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Extension\ExtensionProfiler;
use ON\FS\PathRegistry;
use ON\Init\Init;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Middleware\PipelineExtension;
use ON\RequestStackInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ClockworkMiddlewareTest extends TestCase
{
	private string $storageDir;

	protected function setUp(): void
	{
		$this->storageDir = sys_get_temp_dir() . '/overnight-clockwork-test-' . bin2hex(random_bytes(8));
		mkdir($this->storageDir, 0755, true);
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->storageDir);
	}

	public function testAcceptsRequestStackInterfaceAndRespectsWebEnableFalse(): void
	{
		$clockwork = Clockwork::init([
			'storage_files_path' => $this->storageDir,
			'register_helpers' => false,
			'api' => '/__clockwork/',
			'web' => ['enable' => false],
		]);

		$stack = $this->createStack(isMain: false);
		$middleware = new ClockworkMiddleware($clockwork, $stack);

		$handlerCalled = false;
		$handler = new class ($handlerCalled) implements RequestHandlerInterface {
			public function __construct(private bool &$called)
			{
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				$this->called = true;

				return new TextResponse('app-handled');
			}
		};

		$response = $middleware->process(new ServerRequest([], [], '/clockwork', 'GET'), $handler);

		$this->assertTrue($handlerCalled);
		$this->assertSame('app-handled', (string) $response->getBody());
	}

	public function testEndsControllerEventOnMainRequests(): void
	{
		$clockwork = Clockwork::init([
			'storage_files_path' => $this->storageDir,
			'register_helpers' => false,
			'api' => '/__clockwork/',
			'web' => ['enable' => false],
		]);

		$stack = $this->createStack(isMain: true);
		$middleware = new ClockworkMiddleware($clockwork, $stack);

		$handler = new class () implements RequestHandlerInterface {
			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return new TextResponse('ok');
			}
		};

		$response = $middleware->process(new ServerRequest([], [], '/hello', 'GET'), $handler);

		$this->assertSame('ok', (string) $response->getBody());
		$controllerEvent = $clockwork->timeline()->find('Controller');
		$this->assertNotNull($controllerEvent);
		$this->assertNotNull($controllerEvent->end, 'Controller timeline event must be ended on main requests.');
	}

	public function testPsrLoggerDataSourceLogsPsr3LevelContextAndSurvivesReset(): void
	{
		$logger = new Logger('test');
		$dataSource = new PsrLoggerDataSource($logger);

		$logger->warning('First message', ['id' => 1]);
		$req1 = new ClockworkRequest();
		$dataSource->resolve($req1);

		$messages = $req1->log()->toArray();
		$this->assertCount(1, $messages);
		$this->assertSame('warning', $messages[0]['level']);
		$this->assertSame('First message', $messages[0]['message']);
		$this->assertSame(1, $messages[0]['context']['id']);

		$dataSource->reset();

		$logger->error('Second message', ['id' => 2]);
		$req2 = new ClockworkRequest();
		$dataSource->resolve($req2);

		$messages = $req2->log()->toArray();
		$this->assertCount(1, $messages);
		$this->assertSame('error', $messages[0]['level']);
		$this->assertSame('Second message', $messages[0]['message']);
		$this->assertSame(2, $messages[0]['context']['id']);
	}

	public function testClockworkExtensionGatesActivationOnDebugAndChecksMonologLogger(): void
	{
		$prodApp = $this->createMock(Application::class);
		$prodApp->method('isDebug')->willReturn(false);

		$prodExt = new ClockworkExtension($prodApp);
		$this->assertFalse($prodExt->isActive());

		$init = new Init();
		$init->registerExtension($prodExt);
		$prodContainer = $this->createMock(ContainerInterface::class);
		$prodContainer->expects($this->never())->method('has');
		$pipeline = $this->createMock(PipelineExtension::class);
		$pipeline->expects($this->never())->method('pipe');
		$init->emit(new ContainerReadyEvent($this->createStub(ContainerExtension::class), $prodContainer));
		$init->emit(new PipelineReadyEvent($pipeline, $prodContainer));

		// Non-Monolog PSR-3 logger should not crash onContainerReady when active
		$debugApp = $this->createMock(Application::class);
		$debugApp->method('isDebug')->willReturn(true);
		$debugApp->paths = new PathRegistry(['project' => $this->storageDir, 'var' => $this->storageDir], $this->storageDir);
		$debugApp->method('extensionProfiler')->willReturn(new ExtensionProfiler(false));

		$activeExt = new ClockworkExtension($debugApp, [
			'storage_files_path' => $this->storageDir,
			'register_helpers' => false,
		]);
		$this->assertTrue($activeExt->isActive());

		$container = new class () implements ContainerInterface {
			public array $entries = [];

			public function get(string $id): mixed
			{
				return $this->entries[$id];
			}

			public function has(string $id): bool
			{
				return array_key_exists($id, $this->entries);
			}

			public function set(string $id, mixed $value): void
			{
				$this->entries[$id] = $value;
			}
		};
		$container->set(LoggerInterface::class, new NullLogger());

		$init = new Init();
		$init->registerExtension($activeExt);
		$init->emit(new ContainerReadyEvent(
			$this->createMock(ContainerExtension::class),
			$container
		));

		$this->assertTrue($container->has(Clockwork::class));
	}

	public function testActiveApplicationUsesItsOwnClockworkSettingsWhenHelpersAlreadyExist(): void
	{
		$app = $this->createMock(Application::class);
		$app->method('isDebug')->willReturn(true);
		$app->method('extensionProfiler')->willReturn(new ExtensionProfiler(false));
		$app->paths = new PathRegistry(['project' => $this->storageDir, 'var' => $this->storageDir], $this->storageDir);

		new ClockworkExtension($app, [
			'storage_files_path' => $this->storageDir,
			'register_helpers' => true,
			'web' => ['enable' => '/profiler'],
		]);
		$this->assertSame('/profiler', Clockwork::instance()->getConfig()['web']['enable']);

		new ClockworkExtension($app, [
			'storage_files_path' => $this->storageDir,
			'register_helpers' => true,
			'web' => ['enable' => false],
		]);
		$this->assertFalse(Clockwork::instance()->getConfig()['web']['enable']);
	}

	private function createStack(bool $isMain): RequestStackInterface
	{
		return new class ($isMain) implements RequestStackInterface {
			public function __construct(private bool $isMain)
			{
			}

			public function beginRequest(ServerRequestInterface $request, callable $callback): mixed
			{
				return $callback();
			}

			public function usingRequest(ServerRequestInterface $request, callable $callback): mixed
			{
				return $callback();
			}

			public function isMainRequest(ServerRequestInterface $request): bool
			{
				return $this->isMain;
			}

			public function isCurrentMainRequest(): bool
			{
				return $this->isMain;
			}

			public function push(ServerRequestInterface $request): void
			{
			}

			public function pop(): ?ServerRequestInterface
			{
				return null;
			}

			public function getCurrentRequest(): ?ServerRequestInterface
			{
				return null;
			}

			public function getMainRequest(): ?ServerRequestInterface
			{
				return null;
			}

			public function getParentRequest(): ?ServerRequestInterface
			{
				return null;
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
