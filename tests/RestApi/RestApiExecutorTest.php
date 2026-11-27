<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Event\EventsExtension;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Event\RestApiActivatedEvent;
use ON\RestApi\RestApiExecutorInterface;
use ON\RestApi\RestApiExtension;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RestApiExecutorTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-rest-executor-test-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', 0755, true);
		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");
		RestApiLazyActivationProbeExtension::$initCalls = 0;
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		$this->removeDirectory($this->projectDir);
	}

	public function testExecutorServiceActivatesLazilyAndNotifiesInitAndRuntimeListenersOnce(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				EventsExtension::class => [],
				RestApiExtension::class => [],
				RestApiLazyActivationProbeExtension::class => [],
			],
			'debug' => true,
		]);

		// Lazy event must not fire during boot
		$this->assertSame(0, RestApiLazyActivationProbeExtension::$initCalls);

		$container = $app->ext(ContainerExtension::class)->getContainer();
		$runtimeCalls = 0;
		$eventsExt = $app->ext(EventsExtension::class);
		$eventsExt->registerListener(RestApiActivatedEvent::class, function () use (&$runtimeCalls): void {
			$runtimeCalls++;
		});

		$executor = $container->get(RestApiExecutorInterface::class);
		$this->assertInstanceOf(RestApiExecutorInterface::class, $executor);
		$this->assertSame(0, RestApiLazyActivationProbeExtension::$initCalls);

		$action = new class () implements RestActionInterface {
			public function __invoke(array $params = [], mixed $payload = null, ?array $options = null): mixed
			{
				return ['ok' => $params['value'] ?? null];
			}
		};

		$first = $executor->execute($action, ['value' => 1]);
		$second = $executor->execute($action, ['value' => 2]);

		$this->assertSame(['ok' => 1], $first);
		$this->assertSame(['ok' => 2], $second);
		$this->assertSame(1, RestApiLazyActivationProbeExtension::$initCalls);
		$this->assertSame(1, $runtimeCalls);
		$this->assertInstanceOf(EventDispatcherInterface::class, $container->get(EventDispatcherInterface::class));
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

final class RestApiLazyActivationProbeExtension extends AbstractExtension
{
	public static int $initCalls = 0;

	#[On(optional: true)]
	public function onRestApiActivated(RestApiActivatedEvent $event): void
	{
		self::$initCalls++;
	}
}
