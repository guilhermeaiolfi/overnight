<?php

declare(strict_types=1);

namespace Tests\ON\DB\Cycle;

use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Fiber;
use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\DB\Container\CycleDatabaseFactory;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CycleDatabaseFactoryTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;
	private mixed $previousAppDebug;
	private bool $hadAppDebug;

	protected function setUp(): void
	{
		$this->previousCwd = (string) getcwd();
		$this->hadAppDebug = array_key_exists('APP_DEBUG', $_ENV);
		$this->previousAppDebug = $_ENV['APP_DEBUG'] ?? null;
		$this->projectDir = sys_get_temp_dir() . '/overnight-cycle-factory-test-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', 0755, true);
		mkdir($this->projectDir . '/var/cache', 0755, true);
		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=false\nAPP_ENV=testing\n");
		chdir($this->projectDir);
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		if ($this->hadAppDebug) {
			$_ENV['APP_DEBUG'] = $this->previousAppDebug;
		} else {
			unset($_ENV['APP_DEBUG']);
		}
		$this->removeDirectory($this->projectDir);
	}

	public function testWritesPerDatabasePhpSchemaCacheAtomicallyAndUsesAppIsDebug(): void
	{
		// Unset APP_DEBUG to confirm factory relies on $app->isDebug() rather than $_ENV["APP_DEBUG"]
		unset($_ENV['APP_DEBUG']);

		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['debug' => false],
				ContainerExtension::class => [],
			],
			'debug' => false,
		]);

		$dbCfg = $this->createSqliteDbConfig('analytics');
		$registry = new Registry();
		$registry->collection('metric')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('label', 'string')->end()
			->end();

		$factory = new CycleDatabaseFactory($app, $app->ext('container')->getContainer());
		$db = $factory($dbCfg, $registry, 'analytics');

		$files = glob($this->projectDir . '/var/cache/cycle.schema.analytics.*.php') ?: [];
		$this->assertCount(1, $files);
		$cacheFile = $files[0];
		$this->assertFileExists($cacheFile);
		$this->assertSame([], glob($cacheFile . '.*.tmp') ?: []);

		$raw = (string) file_get_contents($cacheFile);
		$this->assertStringStartsWith("<?php\n\nreturn ", $raw);

		$schemaArray = require $cacheFile;
		$this->assertIsArray($schemaArray);
		$this->assertArrayHasKey('metric', $schemaArray);
		$this->assertInstanceOf(CycleDatabase::class, $db);

		// Second call with empty registry loads directly from the PHP schema cache
		$warmDb = $factory($dbCfg, new Registry(), 'analytics');
		$this->assertTrue($warmDb->getResource()->getSchema()->defines('metric'));
	}

	public function testSanitizesDatabaseNameInCacheFilename(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['debug' => false],
			],
			'debug' => false,
		]);

		$dbName = '../evil/db';
		$dbCfg = $this->createSqliteDbConfig($dbName);
		$registry = new Registry();
		$registry->collection('item')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->end();

		$factory = new CycleDatabaseFactory($app);
		$factory($dbCfg, $registry, $dbName);

		$files = glob($this->projectDir . '/var/cache/cycle.schema.*.php') ?: [];
		$this->assertCount(1, $files);
		$this->assertSame(
			realpath($this->projectDir . '/var/cache'),
			realpath(dirname($files[0]))
		);
	}

	public function testOverlappingFactoryCallsKeepTheirOwnSchemaCachePaths(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'debug' => false,
		]);
		$config = $this->createSqliteDbConfig('analytics');
		$config->set('databases.reporting', $config->getDatabase('analytics'));
		$analytics = new Registry();
		$analytics->collection('metric')->primaryKey('id')->field('id', 'primary')->end()->end();
		$reporting = new Registry();
		$reporting->collection('report')->primaryKey('id')->field('id', 'primary')->end()->end();
		$factory = new class ($app) extends CycleDatabaseFactory {
			protected function compileSchema(Registry $registry, $dbal): array
			{
				if ($registry->hasCollection('metric')) {
					Fiber::suspend();
				}

				return parent::compileSchema($registry, $dbal);
			}
		};

		$firstCall = new Fiber(static fn () => $factory($config, $analytics, 'analytics'));
		$firstCall->start();
		$factory($config, $reporting, 'reporting');
		$firstCall->resume();

		$this->assertTrue($factory($config, new Registry(), 'analytics')->getResource()->getSchema()->defines('metric'));
		$this->assertTrue($factory($config, new Registry(), 'reporting')->getResource()->getSchema()->defines('report'));
	}

	public function testPassesContainerConversionGatewayToCycleRegistryGenerator(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['debug' => false],
				ContainerExtension::class => [],
			],
			'debug' => false,
		]);

		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(CustomCycleTextFieldType::class);

		$container = $app->ext('container')->getContainer();
		$container->set(ConversionGateway::class, $gateway);

		$dbCfg = $this->createSqliteDbConfig('custom_gateway');
		$registry = new Registry();
		$registry->collection('note')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('body', 'custom_cycle_text')->end()
			->end();

		$factory = new CycleDatabaseFactory($app, $container);
		$db = $factory($dbCfg, $registry, 'custom_gateway');

		$this->assertTrue($db->getResource()->getSchema()->defines('note'));
	}

	private function createSqliteDbConfig(string $name): DatabaseConfig
	{
		$dbCfg = new DatabaseConfig();
		$dbCfg->addDatabase(
			name: $name,
			class: CycleDatabase::class,
			options: [
				'default' => 'default',
				'databases' => [
					'default' => ['connection' => 'sqlite'],
				],
				'connections' => [
					'sqlite' => new SQLiteDriverConfig(
						connection: new MemoryConnectionConfig(),
					),
				],
			]
		);

		return $dbCfg;
	}

	private function removeDirectory(string $directory): void
	{
		if (! is_dir($directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir() && ! $item->isLink()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($directory);
	}
}

final class CustomCycleTextFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['custom_cycle_text'];
	}

	public static function getStorageType(): string
	{
		return 'text';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return (string) $value;
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return (string) $value;
	}
}
