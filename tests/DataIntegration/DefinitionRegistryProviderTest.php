<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use FilesystemIterator;
use ON\Application;
use ON\Cache\CacheClearerRegistry;
use ON\Cache\CacheExtension;
use ON\Config\ConfigExtension;
use ON\Console\ConsoleExtension;
use ON\Container\ContainerExtension;
use ON\Data\Definition\Registry;
use ON\DataIntegration\Command\DefinitionsClearCommand;
use ON\DataIntegration\Command\DefinitionsWarmupCommand;
use ON\DataIntegration\DataExtension;
use ON\DataIntegration\Definition\DefinitionCache;
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DefinitionRegistryProviderTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = $this->createProjectDir();
		DataDefinitionProbeExtension::reset();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		DataDefinitionProbeExtension::reset();
		$this->removeDirectory($this->projectDir);
	}

	public function testColdBuildDispatchesBuildEventWritesCacheAndReturnsRestoredRegistry(): void
	{
		$app = $this->createApplication();
		$container = $app->ext('container')->getContainer();

		$registry = $container->get(Registry::class);
		$cache = $container->get(DefinitionCache::class);

		$this->assertInstanceOf(Registry::class, $registry);
		$this->assertSame(1, DataDefinitionProbeExtension::$configureCalls);
		$this->assertSame(1, DataDefinitionProbeExtension::$doneCalls);
		$this->assertFileExists($cache->getFile());
		$this->assertSame(['id'], $registry->getCollection('users')?->getPrimaryKey());
		$this->assertSame(require $cache->getFile(), $registry->all());
		$this->assertStringStartsWith("<?php\n\nreturn array (", file_get_contents($cache->getFile()) ?: '');
	}

	public function testWarmBuildLoadsCacheAndSkipsBuildEvent(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/data-definitions.php';
		$this->writeProjectFiles();
		if (! is_dir(dirname($cacheFile))) {
			mkdir(dirname($cacheFile), 0777, true);
		}
		file_put_contents($cacheFile, "<?php\n\nreturn " . var_export($this->usersDefinitionArray('cached_users'), true) . ";\n");

		$app = $this->createApplication(writeFiles: false);
		$registry = $app->ext('container')->getContainer()->get(Registry::class);

		$this->assertSame(0, DataDefinitionProbeExtension::$configureCalls);
		$this->assertSame(0, DataDefinitionProbeExtension::$doneCalls);
		$this->assertTrue($registry->hasCollection('cached_users'));
		$this->assertFalse($registry->hasCollection('users'));
	}

	public function testExportRestoreSymmetry(): void
	{
		$registry = new Registry();
		DataDefinitionProbeExtension::defineUsers($registry);

		$exported = $registry->all();
		$restored = new Registry($exported);

		$this->assertSame($exported, $restored->all());
		$this->assertSame($restored->all(), (new Registry($restored->all()))->all());
	}

	public function testAtomicCacheWriteCreatesPhpFileWithoutTemporarySiblings(): void
	{
		$this->writeProjectFiles();
		$cache = new DefinitionCache($this->projectDir . '/var/cache/data-definitions.php');

		$cache->write($this->usersDefinitionArray('users'));

		$this->assertFileExists($cache->getFile());
		$this->assertSame($this->usersDefinitionArray('users'), require $cache->getFile());
		$this->assertSame([], glob($cache->getFile() . '.*.tmp') ?: []);
	}

	public function testContainerCanResolveDefinitionCommands(): void
	{
		$app = $this->createApplication();
		$container = $app->ext('container')->getContainer();

		$this->assertInstanceOf(DefinitionsClearCommand::class, $container->get(DefinitionsClearCommand::class));
		$this->assertInstanceOf(DefinitionsWarmupCommand::class, $container->get(DefinitionsWarmupCommand::class));
	}

	public function testDefinitionCommandsClearAndWarmCache(): void
	{
		$app = $this->createApplication();
		$container = $app->ext('container')->getContainer();
		$cache = $container->get(DefinitionCache::class);

		$this->assertSame(Command::SUCCESS, (new CommandTester($container->get(DefinitionsWarmupCommand::class)))->execute([]));
		$this->assertFileExists($cache->getFile());

		$this->assertSame(Command::SUCCESS, (new CommandTester($container->get(DefinitionsClearCommand::class)))->execute([]));
		$this->assertFileDoesNotExist($cache->getFile());
	}

	public function testCacheExtensionRegistersDataDefinitionClearer(): void
	{
		$app = $this->createApplication(includeCache: true);
		$registry = $app->ext('container')->getContainer()->get(CacheClearerRegistry::class);

		$this->assertTrue($registry->has('data-definitions'));
	}

	private function createApplication(bool $writeFiles = true, bool $includeCache = false): Application
	{
		if ($writeFiles) {
			$this->writeProjectFiles();
		}

		return new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				...($includeCache ? [CacheExtension::class => []] : []),
				ConsoleExtension::class => [],
				DataExtension::class => [],
				DataDefinitionProbeExtension::class => [],
			],
			'debug' => true,
		]);
	}

	private function writeProjectFiles(): void
	{
		if (! is_dir($this->projectDir . '/config')) {
			mkdir($this->projectDir . '/config', 0777, true);
		}

		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");
	}

	/**
	 * @return array<string, mixed>
	 */
	private function usersDefinitionArray(string $collection): array
	{
		$registry = new Registry();
		$registry->collection($collection)
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('email', 'string')->end();

		return $registry->all();
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . '/overnight-data-integration-test-' . bin2hex(random_bytes(8));
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

final class DataDefinitionProbeExtension extends AbstractExtension
{
	public static int $configureCalls = 0;
	public static int $doneCalls = 0;

	public function register(Init $init): void
	{
		$init->on(DataDefinitionConfigureEvent::class, [$this, 'onDataDefinitionConfigure'])
			->done([$this, 'onDataDefinitionConfigureDone']);
	}

	public static function reset(): void
	{
		self::$configureCalls = 0;
		self::$doneCalls = 0;
	}

	public static function defineUsers(Registry $registry): void
	{
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('email', 'string')->end();
	}

	public function onDataDefinitionConfigure(DataDefinitionConfigureEvent $event): void
	{
		self::$configureCalls++;
		self::defineUsers($event->registry);
	}

	public function onDataDefinitionConfigureDone(DataDefinitionConfigureEvent $event): void
	{
		self::$doneCalls++;
	}
}
