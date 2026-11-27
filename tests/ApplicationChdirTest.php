<?php

declare(strict_types=1);

namespace Tests\ON;

use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Migrations\FileRepository;
use DI\Container;
use FilesystemIterator;
use Laminas\Diactoros\ServerRequest;
use League\Plates\Engine;
use Monolog\Logger;
use ON\Application;
use ON\Cache\CacheConfig;
use ON\Cache\CacheExtension;
use ON\Cache\Container\FilesystemAdapterFactory;
use ON\Config\AppConfig;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseExtension;
use ON\Discovery\CacheAdapter\AggressiveCacheAdapter;
use ON\Discovery\CacheAdapter\NeverCacheAdapter;
use ON\Discovery\CacheAdapter\TimestampCacheAdapter;
use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryLocation;
use ON\FileRouting\FileRouter;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRoutingExtension;
use ON\FileRouting\Page\ApiPage;
use ON\Logging\Container\LoggerFactory;
use ON\Logging\LoggingConfig;
use ON\Logging\LoggingExtension;
use ON\Maintenance\MaintenanceCommand;
use ON\Router\RouterExtension;
use ON\Router\RouterInterface;
use ON\View\Latte\LatteExtension;
use ON\View\Latte\LatteRenderer;
use ON\View\ViewConfig;
use ON\View\ViewExtension;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;

#[BackupGlobals(true)]
final class ApplicationChdirTest extends TestCase
{
	private string $originalCwd;
	private string $project;
	private ?Application $originalApplication;

	protected function setUp(): void
	{
		$this->originalCwd = (string) getcwd();
		$this->originalApplication = Application::$instance;
		$this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-paths-' . bin2hex(random_bytes(8));
		mkdir($this->path('public'), 0755, true);
		chdir($this->path('public'));
	}

	protected function tearDown(): void
	{
		chdir($this->originalCwd);
		Application::$instance = $this->originalApplication;
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($this->project);
	}

	public function testBootstrapLoadsDefaultProjectConfigDotenvAndPipelineFromPublic(): void
	{
		$this->write('.env', "OVERNIGHT_PATHS_TEST=project\n");
		$this->write('public/.env', "OVERNIGHT_PATHS_TEST=public\n");
		$this->write('config/extensions.php', <<<'PHP'
			<?php return [
				ON\Config\ConfigExtension::class => [],
				ON\Container\ContainerExtension::class => [],
				ON\Middleware\PipelineExtension::class => [],
			];
			PHP);
		$this->write('config/app.all.php', '<?php return [ON\Config\AppConfig::class => ["project_config_loaded" => true]];');
		$this->write('config/pipeline.php', '<?php return static function (ON\Application $app) { $app->pipelineFileLoaded = true; };');

		$cwd = getcwd();
		$app = new Application(['debug' => true]);

		$this->assertSame($cwd, getcwd());
		$this->assertSame(realpath($this->project), $app->paths->get('project')->getAbsolutePath());
		$this->assertSame('project', $_ENV['OVERNIGHT_PATHS_TEST']);
		$this->assertTrue($app->ext('config')->get(AppConfig::class)->get('project_config_loaded'));
		$this->assertTrue($app->pipelineFileLoaded);
	}

	public function testCustomConfigDirectoryExplicitExtensionsAndMaintenanceDotenv(): void
	{
		$this->write('settings/[config]{local}/extensions.php', '<?php return [ON\Config\ConfigExtension::class => []];');
		$this->write('settings/[config]{local}/app.all.php', '<?php return [ON\Config\AppConfig::class => ["source" => "custom"]];');
		$this->write('config/app.all.php', '<?php return [ON\Config\AppConfig::class => ["source" => "default"]];');
		$this->write('settings/environment', "OVERNIGHT_PATHS_TEST=custom\n");
		$this->write('public/.env', "OVERNIGHT_PATHS_TEST=public\n");
		$options = [
			'paths' => ['project' => $this->project, 'config' => 'settings/[config]{local}'],
			'dotenv_path' => 'settings/environment',
			'dotenv_overload' => true,
			'debug' => true,
		];

		$cwd = getcwd();
		$app = new Application($options);
		$this->assertSame($cwd, getcwd());
		$this->assertSame('custom', $_ENV['OVERNIGHT_PATHS_TEST']);
		$this->assertSame('custom', $app->ext('config')->get(AppConfig::class)->get('source'));

		$this->write('bootstrap/extensions.php', '<?php return [];');
		$app = new Application(['extensions' => 'bootstrap/extensions.php'] + $options);
		$this->assertFalse($app->hasExtension('config'));
		$command = new CommandTester(new MaintenanceCommand($app));
		$this->assertSame(0, $command->execute(['onoff' => 'on']));
		$this->assertStringContainsString('APP_MAINTENANCE=true', file_get_contents($this->path('settings/environment')));
		$this->assertSame(0, $command->execute(['onoff' => 'off']));
		$this->assertStringContainsString('APP_MAINTENANCE=false', file_get_contents($this->path('settings/environment')));
		$this->assertSame("OVERNIGHT_PATHS_TEST=public\n", file_get_contents($this->path('public/.env')));
		$this->assertFileDoesNotExist($this->path('.env'));
		$this->assertSame($cwd, getcwd());
	}

	public function testWarmConfigLoadsLegacyRelativeCacheException(): void
	{
		$this->write('config/uncached.all.php', '<?php return (object) ["source" => "project"];');
		$this->write('public/config/uncached.all.php', '<?php return (object) ["source" => "public"];');
		$this->write('var/config-cache.php', "<?php\nreturn ['data' => [], 'cacheExceptions' => ['config/uncached.all.php']];");

		$app = $this->boot([ConfigExtension::class => ['cachePath' => 'var/config-cache.php', 'debug' => false]]);

		$this->assertSame('project', $app->ext('config')->get(stdClass::class)->source);
	}

	public function testRouterWritesAndClearsTheSameProjectCacheAndLoggingKeepsStreams(): void
	{
		$this->write('config/router.all.php', <<<'PHP'
			<?php return static function (ON\Router\RouterConfig $config): void {
				$config->set('cache_enabled', true);
				$config->set('cache_file', 'var/runtime/router.php');
				$config->set('baseHref', '/');
				$config->addRoute('/health', 'HealthPage::index', ['GET'], 'health');
			};
			PHP);
		$app = $this->boot([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			CacheExtension::class => [],
			RouterExtension::class => [],
			LoggingExtension::class => [],
		]);
		$container = $app->ext('container')->getContainer();
		$request = new ServerRequest([], [], '/health', 'GET');
		$this->assertTrue($container->get(RouterInterface::class)->match($request)->isSuccess());
		$this->assertFileExists($this->path('var/runtime/router.php'));
		$this->write('public/var/runtime/router.php', 'cwd cache');
		$app->ext('cache')->getClearerRegistry()->get('router')->clear($container, new NullOutput());
		$this->assertFileDoesNotExist($this->path('var/runtime/router.php'));
		$this->assertSame('cwd cache', file_get_contents($this->path('public/var/runtime/router.php')));

		$logger = $container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$logger->warning('project log');
		$logger->close();
		$logPath = 'var/log/app.' . date('Ymd') . '.log';
		$this->assertStringContainsString('project log', file_get_contents($this->path($logPath)));
		$this->assertFileDoesNotExist($this->path('public/' . $logPath));

		$container->get(LoggingConfig::class)->set('default.path', 'php://memory');
		$logger = (new LoggerFactory())($container);
		$this->assertInstanceOf(Logger::class, $logger);
		$logger->warning('memory log');
		$this->assertSame('php://memory', $logger->getHandlers()[0]->getUrl());
		$logger->close();
	}

	public function testStandaloneFactoriesDoNotAutowireAnApplication(): void
	{
		$container = new Container();
		$container->set(LoggingConfig::class, new LoggingConfig(['default' => ['type' => 'file', 'path' => 'local.log']]));
		$container->set(CacheConfig::class, new CacheConfig(['adapter' => ['directory' => 'local-cache']]));

		$logger = (new LoggerFactory())($container);
		$this->assertInstanceOf(Logger::class, $logger);
		$logger->warning('standalone');
		$logger->close();
		$this->assertFileExists($this->path('public/local.log'));
		$cache = (new FilesystemAdapterFactory())($container);
		$this->assertTrue($cache->save($cache->getItem('test')->set('standalone')));
		$this->assertDirectoryExists($this->path('public/local-cache'));
		$this->assertNotContains(Application::class, $container->getKnownEntryNames());
	}

	public static function discoveryAdapters(): iterable
	{
		yield 'never' => [NeverCacheAdapter::class];
		yield 'timestamp' => [TimestampCacheAdapter::class];
		yield 'aggressive' => [AggressiveCacheAdapter::class];
	}

	#[DataProvider('discoveryAdapters')]
	public function testDiscoveryResolvesRelativePatternsAndPreservesWildcards(string $adapterClass): void
	{
		$this->write('[discovery]/src/deep/nested/Found.php', '<?php');
		$this->write('[discovery]/features/partA/Matched.php', '<?php');
		$this->write('[discovery]/features/partB/Excluded.php', '<?php');
		$this->write('absolute/Absolute.php', '<?php');
		$this->write('public/src/deep/Wrong.php', '<?php');

		$found = [];
		$discoverer = $this->createMock(DiscoverInterface::class);
		$discoverer->expects($this->exactly(3))->method('discover')->willReturnCallback(
			static function (SplFileInfo $file) use (&$found): void {
				$found[] = $file->getFilename();
			}
		);
		$location = new DiscoveryLocation('app', ['src/**', 'features/pa?t[A]', $this->path('absolute')], $adapterClass);
		$app = new Application([
			'paths' => ['project' => $this->path('[discovery]')],
			'extensions' => [],
			'disable_dotenv' => true,
			'debug' => true,
		]);
		$adapter = new $adapterClass(new AppConfig(), $app);
		$adapter->update([$discoverer], $location);

		$this->assertEqualsCanonicalizing(['Absolute.php', 'Found.php', 'Matched.php'], $found);
	}

	public function testFileRoutingAndRenderersUsePathsOverriddenByConfigFiles(): void
	{
		$this->write('config/view.all.php', <<<'PHP'
			<?php return [
				ON\FileRouting\FileRoutingConfig::class => [
					'pagesPath' => 'src/content',
					'cachePath' => 'var/custom-pages',
					'template' => ['namespace' => 'pages'],
				],
				ON\View\ViewConfig::class => [
					'templates' => ['extension' => 'phtml', 'paths' => ['site' => 'templates']],
					'plates' => ['paths' => ['platesOnly' => 'plates']],
					'latte' => ['tempDirectory' => 'var/custom-latte'],
				],
			];
			PHP);
		$this->write('src/content/about.php', 'compiled page');
		$this->write('templates/hello.latte', 'Hello {$name}');
		$this->write('templates/hello.phtml', 'from shared templates');
		$this->write('plates/hello.phtml', 'from Plates configuration');
		$extensions = [
			ConfigExtension::class => ['debug' => false],
			ContainerExtension::class => [],
			RouterExtension::class => [],
			ViewExtension::class => [],
			LatteExtension::class => [],
			FileRoutingExtension::class => [],
		];
		$app = $this->boot($extensions);
		$container = $app->ext('container')->getContainer();
		$request = new ServerRequest([], [], '/about', 'GET');
		$result = $container->get(FileRouter::class)->match($request);
		$this->assertTrue($result->isSuccess());
		$this->assertSame(realpath($this->path('src/content/about.php')), $result->get('_fileController'));
		$container->get(FileRoutingCache::class)->get($result->get('_fileController'));
		$this->assertFileExists($this->path('var/custom-pages/about.phtml'));
		$this->assertSame($this->path('var/custom-pages'), $container->get(ViewConfig::class)->get('templates.paths.pages'));
		$this->assertSame('about.php', $container->get(ApiPage::class)->index($request)->getPayload()['result'][0]['path']);

		$latte = $container->get(LatteRenderer::class);
		$this->assertSame('Hello Overnight', $latte->render(null, 'site::hello', ['name' => 'Overnight'], ['mode' => 'fragment']));
		$this->assertNotEmpty(glob(str_replace('\\', '/', $this->path('var/custom-latte/*.php'))));
		$plates = $container->get(Engine::class);
		$this->assertSame('from shared templates', $plates->render('site::hello'));
		$this->assertSame('from Plates configuration', $plates->render('platesOnly::hello'));
		$this->assertSame('compiled page', $plates->render('pages::about'));
		$this->assertDirectoryDoesNotExist($this->path('public/var/custom-pages'));
		$this->assertDirectoryDoesNotExist($this->path('public/var/custom-latte'));

		$this->assertFileExists($this->path('var/cache/config.php'));
		$warmContainer = $this->boot($extensions)->ext('container')->getContainer();
		$this->assertSame('compiled page', $warmContainer->get(Engine::class)->render('pages::about'));
	}

	public function testMigrationRepositoryAndSqliteFileConfigurationUseTheProjectDirectory(): void
	{
		$this->write('config/database.all.php', <<<'PHP'
			<?php
			$config = new ON\DB\DatabaseConfig();
			$config->set('migration', new Cycle\Migrations\Config\MigrationConfig([
				'directory' => 'data/migrations/',
				'vendorDirectories' => ['vendor/migrations/'],
				'table' => 'migration_history',
				'safe' => true,
				'namespace' => 'ProjectMigration',
			]));
			$config->addDatabase('pdo', 'sqlite:data/app.sqlite');
			$config->addDatabase('cycle', class: ON\DB\Cycle\CycleDatabase::class, options: [
				'connections' => ['sqlite' => new Cycle\Database\Config\SQLiteDriverConfig(
					new Cycle\Database\Config\SQLite\FileConnectionConfig('data/cycle.sqlite'),
				)],
			]);
			return $config;
			PHP);
		mkdir($this->path('data/migrations'), 0755, true);
		mkdir($this->path('vendor/migrations'), 0755, true);
		$app = $this->boot([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			DatabaseExtension::class => [],
		]);
		$config = $app->ext('container')->getContainer()->get(DatabaseConfig::class);
		$migration = $config->get('migration');
		$this->assertSame($this->path('data/migrations'), $migration->getDirectory());
		$this->assertSame([$this->path('vendor/migrations')], $migration->getVendorDirectories());
		$this->assertSame('migration_history', $migration->getTable());
		$this->assertSame('ProjectMigration', $migration->getNamespace());
		$this->assertTrue($migration->isSafe());
		$file = (new FileRepository($migration))->registerMigration('Create Table', 'ProjectMigration\CreateTable', '<?php // migration');
		$this->assertSame(realpath($this->path('data/migrations')), realpath(dirname($file)));
		$this->assertFileExists($file);
		$this->assertSame('sqlite:' . $this->path('data/app.sqlite'), $config->getDatabase('pdo')['dsn']);
		$driver = $config->getDatabase('cycle')['options']['connections']['sqlite'];
		$this->assertInstanceOf(SQLiteDriverConfig::class, $driver);
		$this->assertInstanceOf(FileConnectionConfig::class, $driver->connection);
		$this->assertSame($this->path('data/cycle.sqlite'), $driver->connection->database);
	}

	private function boot(array $extensions): Application
	{
		return new Application([
			'paths' => ['project' => $this->project],
			'extensions' => $extensions,
			'disable_dotenv' => true,
			'debug' => true,
		]);
	}

	private function path(string $path): string
	{
		return $this->project . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
	}

	private function write(string $path, string $content): void
	{
		$file = $this->path($path);
		if (! is_dir(dirname($file))) {
			mkdir(dirname($file), 0755, true);
		}
		file_put_contents($file, $content);
	}
}
