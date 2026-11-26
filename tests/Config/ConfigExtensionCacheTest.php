<?php

declare(strict_types=1);

namespace Tests\ON\Config;

use FilesystemIterator;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Discovery\CacheAdapter\AggressiveCacheAdapter;
use ON\Discovery\DiscoveryLocation;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use stdClass;

final class ConfigExtensionCacheTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = (string) getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-config-cache-' . uniqid('', true);
		mkdir($this->projectDir . '/config', 0777, true);
		mkdir($this->projectDir . '/var/cache', 0777, true);
		file_put_contents($this->projectDir . '/.env', '');
		file_put_contents($this->projectDir . '/config/extensions.php', "<?php\nreturn [];\n");
		chdir($this->projectDir);
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		$this->removeDirectory($this->projectDir);
	}

	public function testSaveCacheThrowsWhenFactoryClosuresArePresent(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/config.php';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('non-serializable values');

		try {
			new Application([
				'paths' => ['project' => $this->projectDir],
				'extensions' => [
					ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
					ContainerExtension::class => [],
					ExtensionWithClosureFactory::class => [],
				],
				'debug' => false,
			]);
		} finally {
			$this->assertFileDoesNotExist($cacheFile);
		}
	}

	public function testSaveCacheWritesWhenFactoriesAreClassNames(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/config.php';

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
				ContainerExtension::class => [],
				ExtensionWithClassFactory::class => [],
			],
			'debug' => false,
		]);

		$this->assertFileExists($cacheFile);
		$raw = (string) file_get_contents($cacheFile);
		$this->assertStringStartsWith("<?php\n\nreturn ", $raw);
		$this->assertSame([], glob($cacheFile . '.*.tmp') ?: []);

		$cache = require $cacheFile;
		$this->assertIsArray($cache);
		$this->assertSame(
			SerialFactory::class,
			$cache['data'][ContainerConfig::class]['definitions']['factories']['demo_service'] ?? null,
		);

		// Verify warm boot loads from the PHP cache file cleanly
		Application::$instance = null;
		$warmApp = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
			],
			'debug' => false,
		]);

		$this->assertSame(
			SerialFactory::class,
			$warmApp->ext('config')->get(ContainerConfig::class)->get('definitions.factories.demo_service'),
		);
	}

	public function testLegacyCacheIsRebuiltWithoutWritingToTheResponse(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/config.php';
		file_put_contents($cacheFile, serialize(['data' => []]));

		ob_start();

		try {
			new Application([
				'paths' => ['project' => $this->projectDir],
				'extensions' => [
					ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
				],
				'debug' => false,
			]);
			$this->assertSame('', ob_get_contents());
		} finally {
			ob_end_clean();
		}

		$this->assertStringStartsWith("<?php\n", (string) file_get_contents($cacheFile));
	}

	public function testSaveCacheRejectsObjectsWithoutSetState(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/config.php';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('non-serializable values');

		try {
			new Application([
				'paths' => ['project' => $this->projectDir],
				'extensions' => [
					ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
					ExtensionWithNonExportableObject::class => [],
				],
				'debug' => false,
			]);
		} finally {
			$this->assertFileDoesNotExist($cacheFile);
		}
	}

	public function testSaveCacheRoundTripsDiscoveryLocationObjects(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/config.php';

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
				ExtensionWithDiscoveryLocation::class => [],
			],
			'debug' => false,
		]);

		$this->assertFileExists($cacheFile);

		Application::$instance = null;
		$warmApp = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => false],
			],
			'debug' => false,
		]);

		$locations = $warmApp->ext('config')->get(AppConfig::class)->get('discovery.locations');
		$this->assertIsArray($locations);
		$this->assertCount(1, $locations);
		$this->assertInstanceOf(DiscoveryLocation::class, $locations[0]);
		$this->assertSame('app', $locations[0]->name);
	}

	public function testDebugModeStillThrowsWhenFactoryClosuresArePresent(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/config.php';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('non-serializable values');

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['cachePath' => $cacheFile, 'debug' => true],
				ContainerExtension::class => [],
				ExtensionWithClosureFactory::class => [],
			],
			'debug' => true,
		]);
	}

	private function removeDirectory(string $directory): void
	{
		if (! is_dir($directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($iterator as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}

		rmdir($directory);
	}
}

final class ExtensionWithClosureFactory extends AbstractExtension
{
	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, static function (ConfigConfigureEvent $event): void {
			$event->config->get(ContainerConfig::class)->addFactories([
				'demo_service' => static fn (): object => new stdClass(),
			]);
		});
	}
}

final class ExtensionWithClassFactory extends AbstractExtension
{
	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, static function (ConfigConfigureEvent $event): void {
			$event->config->get(ContainerConfig::class)->addFactories([
				'demo_service' => SerialFactory::class,
			]);
		});
	}
}

final class SerialFactory
{
	public function __invoke(): object
	{
		return new stdClass();
	}
}

final class NonExportableValue
{
	public function __construct(public string $label = 'nope')
	{
	}
}

final class ExtensionWithNonExportableObject extends AbstractExtension
{
	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, static function (ConfigConfigureEvent $event): void {
			$event->config->get(AppConfig::class)->set('bad_object', new NonExportableValue());
		});
	}
}

final class ExtensionWithDiscoveryLocation extends AbstractExtension
{
	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, static function (ConfigConfigureEvent $event): void {
			$event->config->get(AppConfig::class)->set('discovery.locations', [
				new DiscoveryLocation('app', ['src'], AggressiveCacheAdapter::class),
			]);
		});
	}
}
