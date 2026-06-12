<?php

declare(strict_types=1);

namespace Tests\ON\ORM\Container;

use FilesystemIterator;
use ON\Application;
use ON\Benchmark;
use ON\Config\AppConfig;
use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\ORM\Definition\Registry;
use ON\ORM\Init\Event\OrmConfigureEvent;
use ON\ORM\ORMExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RegistryFactoryBenchmarkTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = $this->createProjectDir();
		Benchmark::$benchmarks = [];
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		Benchmark::$benchmarks = [];
		$this->removeDirectory($this->projectDir);
	}

	public function testRegistryResolutionRecordsBenchmarkTimings(): void
	{
		$app = $this->createApplication([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			ORMExtension::class => [],
			RegistryProbeExtension::class => [],
		]);

		$registry = $app->ext('container')->getContainer()->get(Registry::class);

		$this->assertInstanceOf(Registry::class, $registry);
		$this->assertNotNull($registry->getCollection('bench_probe'));
		$this->assertTrue(Benchmark::has('orm.registry.base'));
		$this->assertTrue(Benchmark::has('orm.registry.configure'));
		$this->assertTrue(Benchmark::has('orm.registry.total'));
		$this->assertGreaterThanOrEqual(0.0, Benchmark::ms('orm.registry.base'));
		$this->assertGreaterThanOrEqual(0.0, Benchmark::ms('orm.registry.configure'));
		$this->assertGreaterThanOrEqual(
			Benchmark::ms('orm.registry.base') + Benchmark::ms('orm.registry.configure'),
			Benchmark::ms('orm.registry.total')
		);
	}

	/**
	 * @param array<class-string, array<string, mixed>> $extensions
	 */
	private function createApplication(array $extensions, bool $debug = false): Application
	{
		$this->writeProjectFiles();

		return new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => $extensions,
			'debug' => $debug,
		]);
	}

	private function writeProjectFiles(): void
	{
		if (! is_dir($this->projectDir . '/config')) {
			mkdir($this->projectDir . '/config', 0777, true);
		}

		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=false\nAPP_ENV=testing\n");
		file_put_contents(
			$this->projectDir . '/config/config.all.php',
			<<<'PHP'
<?php

use ON\Config\AppConfig;
use ON\Container\ContainerConfig;

$config = new ContainerConfig();
$config->addFactory(AppConfig::class, AppConfig::class);

return $config;
PHP
		);

		file_put_contents(
			$this->projectDir . '/config/app.all.php',
			<<<'PHP'
<?php

use ON\Config\AppConfig;

$config = new AppConfig();

return $config;
PHP
		);
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . '/overnight-registry-benchmark-test-' . bin2hex(random_bytes(8));
		mkdir($dir, 0777, true);

		return $dir;
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

final class RegistryProbeExtension extends AbstractExtension
{
	public function register(Init $init): void
	{
		$init->on(OrmConfigureEvent::class, function (OrmConfigureEvent $event): void {
			$event->registry
				->collection('bench_probe')
				->field('id', 'int')->primaryKey(true)->end()
				->end();
		});
	}
}
