<?php

declare(strict_types=1);

namespace Tests\ON\Container;

use FilesystemIterator;
use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Container\Executor\ExecutorFactory;
use ON\Container\ResponseFactory;
use ON\FS\PathRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

final class ContainerRuntimeTest extends TestCase
{
	public function testResponseFactorySetsCustomReasonPhrase(): void
	{
		$factory = new ResponseFactory();

		$custom = $factory->createResponse(201, 'Resource Provisioned');
		$this->assertSame(201, $custom->getStatusCode());
		$this->assertSame('Resource Provisioned', $custom->getReasonPhrase());

		$default = $factory->createResponse(404);
		$this->assertSame(404, $default->getStatusCode());
		$this->assertSame('Not Found', $default->getReasonPhrase());
	}

	public function testExecutorFactoryInjectsTypedServiceBeforeFallingBackToDefaultValue(): void
	{
		$service = new stdClass();
		$service->tag = 'from-container';

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === stdClass::class);
		$container->method('get')->with(stdClass::class)->willReturn($service);

		$executor = (new ExecutorFactory())($container);

		$result = $executor->execute(function (?stdClass $dep = null, ?ResponseFactory $missing = null): array {
			return [$dep, $missing];
		});

		$this->assertSame($service, $result[0]);
		$this->assertNull($result[1]);
	}

	public function testContainerExtensionCreatesCachePathWhenWriteProxiesIsTrue(): void
	{
		$tmpDir = sys_get_temp_dir() . '/overnight-container-ext-' . bin2hex(random_bytes(8));
		mkdir($tmpDir, 0755, true);

		try {
			$proxyDir = $tmpDir . '/nested/proxies';

			$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
			$app->paths = new PathRegistry(['project' => $tmpDir], $tmpDir);

			$config = new ContainerConfig([
				'enable_cache' => false,
				'write_proxies' => true,
				'cache_path' => $proxyDir,
			]);

			$extension = new ContainerExtension($app);
			$extension->createContainer($config);

			$this->assertDirectoryExists($proxyDir);
		} finally {
			$this->removeDirectory($tmpDir);
		}
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
