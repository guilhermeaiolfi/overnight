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
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldTypeInterface;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapping;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\DataIntegration\Command\DefinitionsClearCommand;
use ON\DataIntegration\Command\DefinitionsWarmupCommand;
use ON\DataIntegration\DataExtension;
use ON\DataIntegration\Definition\DefinitionCache;
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Init\InitException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\NullOutput;
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
		FailingDataDefinitionExtension::reset();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		Mapping::resetDefaultGateway();
		DataDefinitionProbeExtension::reset();
		FailingDataDefinitionExtension::reset();
		ConfiguredDataResolver::reset();
		$this->removeDirectory($this->projectDir);
	}

	public function testColdBuildDispatchesBuildEventWritesCacheAndReturnsRestoredRegistry(): void
	{
		$app = $this->createApplication();
		$container = $app->ext('container')->getContainer();

		$registry = $container->get(Registry::class);
		$cache = $container->get(DefinitionCache::class);
		$cachedDefinitions = require $cache->getFile();

		$this->assertInstanceOf(Registry::class, $registry);
		$this->assertSame(1, DataDefinitionProbeExtension::$configureCalls);
		$this->assertSame(1, DataDefinitionProbeExtension::$doneCalls);
		$this->assertFileExists($cache->getFile());
		$this->assertSame(['id'], $registry->getCollection('users')?->getPrimaryKey());
		$this->assertSame($cachedDefinitions, $registry->all());
		$this->assertSame($cachedDefinitions, (new Registry($cachedDefinitions))->all());
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

		$app = $this->createApplication(writeFiles: false, debug: false);
		$registry = $app->ext('container')->getContainer()->get(Registry::class);

		$this->assertSame(0, DataDefinitionProbeExtension::$configureCalls);
		$this->assertSame(0, DataDefinitionProbeExtension::$doneCalls);
		$this->assertTrue($registry->hasCollection('cached_users'));
		$this->assertFalse($registry->hasCollection('users'));
	}

	public function testDebugModeIgnoresCachedDefinitionsAndRebuilds(): void
	{
		$cacheFile = $this->projectDir . '/var/cache/data-definitions.php';
		$this->writeProjectFiles();
		if (! is_dir(dirname($cacheFile))) {
			mkdir(dirname($cacheFile), 0777, true);
		}
		file_put_contents($cacheFile, "<?php\n\nreturn " . var_export($this->usersDefinitionArray('cached_users'), true) . ";\n");

		$app = $this->createApplication(writeFiles: false, debug: true);
		$registry = $app->ext('container')->getContainer()->get(Registry::class);

		$this->assertSame(1, DataDefinitionProbeExtension::$configureCalls);
		$this->assertTrue($registry->hasCollection('users'));
		$this->assertFalse($registry->hasCollection('cached_users'));
	}

	public function testFailingDefinitionListenerDoesNotWriteCache(): void
	{
		$app = $this->createApplication(extraExtensions: [
			FailingDataDefinitionExtension::class => [],
		]);
		$container = $app->ext('container')->getContainer();
		$cache = $container->get(DefinitionCache::class);

		try {
			$container->get(Registry::class);
			$this->fail('Expected InitException was not thrown.');
		} catch (InitException $exception) {
			$this->assertSame(DataDefinitionConfigureEvent::class, $exception->getEvent());
			$this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
			$this->assertSame('definition listener failed', $exception->getPrevious()->getMessage());
		}

		$this->assertSame(1, FailingDataDefinitionExtension::$configureCalls);
		$this->assertFileDoesNotExist($cache->getFile());
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

	public function testCacheClearerClearsContainerManagedDefinitionCache(): void
	{
		$app = $this->createApplication(includeCache: true);
		$container = $app->ext('container')->getContainer();
		$cache = $container->get(DefinitionCache::class);

		$container->get(Registry::class);
		$this->assertFileExists($cache->getFile());

		$container->get(CacheClearerRegistry::class)
			->get('data-definitions')
			->clear($container, new NullOutput());

		$this->assertFileDoesNotExist($cache->getFile());
		$this->assertFalse($cache->exists());
	}

	public function testContainerGatewayBecomesDefaultMapperGateway(): void
	{
		$app = $this->createApplication(includeMapperConfig: true);
		$gateway = $app->ext('container')->getContainer()->get(ConversionGateway::class);

		$this->assertSame($gateway, Mapping::getDefaultGateway());
		$this->assertTrue($gateway->getMapperManager()->has(ConfiguredDataFieldType::class));
		$this->assertTrue($gateway->getMapperManager()->has(ConfiguredDataResolver::class));
	}

	public function testPackageMapHelperUsesConfiguredDefaultGateway(): void
	{
		$this->createApplication(includeMapperConfig: true);

		$result = map(['code' => 'abc'])
			->from(StorageRepresentation::class)
			->to([]);

		$this->assertSame(['code' => 'configured:abc'], $result);
		$this->assertSame(1, ConfiguredDataResolver::$constructCalls);
		$this->assertSame(1, ConfiguredDataResolver::$resolveCalls);
	}

	/**
	 * @param array<class-string, array<string, mixed>> $extraExtensions
	 */
	private function createApplication(
		bool $writeFiles = true,
		bool $includeCache = false,
		bool $includeMapperConfig = false,
		array $extraExtensions = [],
		bool $debug = true,
	): Application {
		if ($writeFiles) {
			$this->writeProjectFiles($includeMapperConfig, $debug);
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
				...$extraExtensions,
			],
			'debug' => $debug,
		]);
	}

	private function writeProjectFiles(bool $includeMapperConfig = false, bool $debug = true): void
	{
		if (! is_dir($this->projectDir . '/config')) {
			mkdir($this->projectDir . '/config', 0777, true);
		}

		$debugFlag = $debug ? 'true' : 'false';
		file_put_contents($this->projectDir . '/.env', "APP_DEBUG={$debugFlag}\nAPP_ENV=testing\n");

		if (! $includeMapperConfig) {
			return;
		}

		file_put_contents(
			$this->projectDir . '/config/data-mapper.all.php',
			<<<'PHP'
<?php

use ON\DataIntegration\Mapper\DataMapperConfig;
use Tests\ON\DataIntegration\ConfiguredDataFieldType;
use Tests\ON\DataIntegration\ConfiguredDataResolver;

$config = new DataMapperConfig();
$config->register(ConfiguredDataFieldType::class);
$config->prepend(ConfiguredDataResolver::class);

return $config;
PHP
		);
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

final class FailingDataDefinitionExtension extends AbstractExtension
{
	public static int $configureCalls = 0;

	public function register(Init $init): void
	{
		$init->on(DataDefinitionConfigureEvent::class, [$this, 'onDataDefinitionConfigure']);
	}

	public static function reset(): void
	{
		self::$configureCalls = 0;
	}

	public function onDataDefinitionConfigure(DataDefinitionConfigureEvent $event): void
	{
		self::$configureCalls++;

		throw new RuntimeException('definition listener failed');
	}
}

final class ConfiguredDataFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['configured-data'];
	}

	public static function getStorageType(): string
	{
		return 'string';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return 'configured:' . (string) $value;
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return (string) $value;
	}
}

final class ConfiguredDataResolverDependency
{
}

final class ConfiguredDataResolver implements NodeResolverInterface
{
	public static int $constructCalls = 0;
	public static int $resolveCalls = 0;

	public function __construct(
		private readonly ConfiguredDataResolverDependency $dependency,
	) {
		self::$constructCalls++;
	}

	public static function reset(): void
	{
		self::$constructCalls = 0;
		self::$resolveCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if (! $this->dependency instanceof ConfiguredDataResolverDependency) {
			return null;
		}

		if ($node->getName() !== 'code') {
			return null;
		}

		self::$resolveCalls++;

		return LeafNodeResolution::named('code', 'configured-data');
	}
}
