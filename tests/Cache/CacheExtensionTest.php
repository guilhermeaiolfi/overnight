<?php

declare(strict_types=1);

namespace Tests\ON\Cache;

use FilesystemIterator;
use ON\Application;
use ON\Cache\CacheClearerRegistry;
use ON\Cache\CacheExtension;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\ConfigExtension;
use ON\Console\Command\ClearCacheCommand;
use ON\Console\ConsoleExtension;
use ON\Container\ContainerExtension;
use ON\DB\DatabaseExtension;
use ON\Extension\AbstractExtension;
use ON\Extension\AutoWiringExtension;
use ON\FileRouting\FileRoutingExtension;
use ON\Image\ImageExtension;
use ON\Init\Init;
use ON\Router\RouterExtension;
use ON\View\Latte\LatteExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheExtensionTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = $this->createProjectDir();
		CacheClearerProbeExtension::$configureCalls = 0;
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		$this->removeDirectory($this->projectDir);
	}

	public function testEmitsCacheClearersConfigureEventInCli(): void
	{
		$app = $this->createApplication(Application::class);

		$this->assertSame(1, CacheClearerProbeExtension::$configureCalls);
		$this->assertInstanceOf(
			CacheClearerRegistry::class,
			$app->ext('container')->getContainer()->get(CacheClearerRegistry::class)
		);
	}

	public function testContainerCanResolveClearCacheCommand(): void
	{
		$app = $this->createApplication(Application::class);

		$this->assertInstanceOf(
			ClearCacheCommand::class,
			$app->ext('container')->getContainer()->get(ClearCacheCommand::class)
		);
	}

	public function testBuiltInExtensionClearersAreRegistered(): void
	{
		$app = $this->createApplication(Application::class, [
			RouterExtension::class => [],
			FileRoutingExtension::class => [],
			LatteExtension::class => [],
			DatabaseExtension::class => [],
			ImageExtension::class => [],
			AutoWiringExtension::class => [],
		]);

		$registry = $app->ext('container')->getContainer()->get(CacheClearerRegistry::class);

		$this->assertTrue($registry->has('container'));
		$this->assertTrue($registry->has('config'));
		$this->assertTrue($registry->has('lifecycle'));
		$this->assertTrue($registry->has('router'));
		$this->assertTrue($registry->has('file-routing'));
		$this->assertTrue($registry->has('latte'));
		$this->assertTrue($registry->has('orm-schema'));
		$this->assertTrue($registry->has('image'));
		$this->assertTrue($registry->has('auto-wiring'));
	}

	public function testBuiltInExtensionClearersRemoveTheirCacheFiles(): void
	{
		$app = $this->createApplication(Application::class, [
			RouterExtension::class => [],
			FileRoutingExtension::class => [],
			LatteExtension::class => [],
			DatabaseExtension::class => [],
			ImageExtension::class => [],
			AutoWiringExtension::class => [],
		]);
		$command = $app->ext('container')->getContainer()->get(ClearCacheCommand::class);
		$tester = new CommandTester($command);

		$routerCache = $this->projectDir . '/var/cache/router.php.cache';
		$configCache = $this->projectDir . '/var/cache/config.php';
		$lifecycleCache = $this->projectDir . '/var/cache/app_lifecycle.php';
		$ormSchemaCache = $this->projectDir . '/var/cache/cycle.schema.php';
		$autoWiringCache = $this->projectDir . '/var/cache/autowiring.php';
		$containerCache = $this->projectDir . '/var/cache/container/CompiledContainer.php';
		$fileRoutingCache = $this->projectDir . '/var/cache/filerouting/page.meta.php';
		$latteCache = $this->projectDir . '/var/cache/latte/template.php';
		$imageCache = $this->projectDir . '/public/i/abcd/image.jpg';

		foreach ([
			$routerCache,
			$configCache,
			$lifecycleCache,
			$ormSchemaCache,
			$autoWiringCache,
			$containerCache,
			$fileRoutingCache,
			$latteCache,
			$imageCache,
		] as $file) {
			if (! is_dir(dirname($file))) {
				mkdir(dirname($file), 0777, true);
			}
			file_put_contents($file, 'cache');
		}

		$this->assertSame(Command::SUCCESS, $tester->execute([
			'clearers' => [
				'router',
				'config',
				'lifecycle',
				'orm-schema',
				'auto-wiring',
				'container',
				'file-routing',
				'latte',
				'image',
			],
		]));

		$this->assertFileDoesNotExist($routerCache);
		$this->assertFileDoesNotExist($configCache);
		$this->assertFileDoesNotExist($lifecycleCache);
		$this->assertFileDoesNotExist($ormSchemaCache);
		$this->assertFileDoesNotExist($autoWiringCache);
		$this->assertFileDoesNotExist($containerCache);
		$this->assertFileDoesNotExist($fileRoutingCache);
		$this->assertFileDoesNotExist($latteCache);
		$this->assertFileDoesNotExist($imageCache);
	}

	public function testDoesNotEmitCacheClearersConfigureEventOutsideCli(): void
	{
		$this->createApplication(NonCliApplication::class);

		$this->assertSame(0, CacheClearerProbeExtension::$configureCalls);
	}

	/**
	 * @param class-string<Application> $applicationClass
	 */
	private function createApplication(string $applicationClass, array $extraExtensions = []): Application
	{
		$this->writeProjectFiles();

		return new $applicationClass([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				CacheExtension::class => [],
				ConsoleExtension::class => [],
				...$extraExtensions,
				CacheClearerProbeExtension::class => [],
			],
			'debug' => true,
		]);
	}

	private function writeProjectFiles(): void
	{
		if (! is_dir($this->projectDir . '/config')) {
			mkdir($this->projectDir . '/config', 0777, true);
		}
		if (! is_dir($this->projectDir . '/public')) {
			mkdir($this->projectDir . '/public', 0777, true);
		}

		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");
		file_put_contents(
			$this->projectDir . '/config/router.all.php',
			<<<'PHP'
<?php

use ON\Router\RouterConfig;

$router = new RouterConfig();
$router->set('cache_file', 'var/cache/router.php.cache');

return $router;
PHP
		);
		file_put_contents(
			$this->projectDir . '/config/file-routing.all.php',
			<<<'PHP'
<?php

use ON\FileRouting\FileRoutingConfig;

$fileRouting = new FileRoutingConfig();
$fileRouting->set('cachePath', 'var/cache/filerouting');

return $fileRouting;
PHP
		);
		file_put_contents(
			$this->projectDir . '/config/view.all.php',
			<<<'PHP'
<?php

use ON\View\ViewConfig;

$view = new ViewConfig();
$view->set('latte.tempDirectory', 'var/cache/latte');

return $view;
PHP
		);
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . '/overnight-cache-extension-test-' . bin2hex(random_bytes(8));
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

final class CacheClearerProbeExtension extends AbstractExtension
{
	public static int $configureCalls = 0;

	public function register(Init $init): void
	{
		$init->on(CacheClearersConfigureEvent::class, function (): void {
			self::$configureCalls++;
		});
	}
}

final class NonCliApplication extends Application
{
	public function isCli(): bool
	{
		return false;
	}
}
