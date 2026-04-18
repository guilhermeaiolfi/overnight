<?php

declare(strict_types=1);

namespace Tests\ON\Extension;

use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Discovery\DiscoveryCache;
use ON\Discovery\DiscoveryExtension;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ExtensionLifecycleTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = $this->createProjectDir();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		$this->removeDirectory($this->projectDir);
	}

	public function testContainerWaitsForConfigReadyWithoutNextTick(): void
	{
		$app = $this->createApplication([
			ContainerExtension::class => [],
			ConfigExtension::class => [],
		]);

		$this->assertTrue($app->ext('container')->isReady());
		$this->assertInstanceOf(ContainerConfig::class, $app->container->get(ContainerConfig::class));
	}

	public function testDiscoveryWaitsForConfigAndContainerReadyWithoutNextTick(): void
	{
		$app = $this->createApplication([
			DiscoveryExtension::class => [],
			ContainerExtension::class => [],
			ConfigExtension::class => [],
		]);

		$this->assertTrue($app->ext('discovery')->isReady());
		$this->assertInstanceOf(DiscoveryCache::class, $app->container->get(DiscoveryCache::class));
	}

	public function testDiscoveryCanFinishWhenContainerWasReadyBeforeInstalledSetup(): void
	{
		$app = $this->createApplication([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			DiscoveryExtension::class => [],
		]);

		$this->assertTrue($app->ext('discovery')->isReady());
	}

	public function testInstalledStateDoesNotAutomaticallyCallSetup(): void
	{
		NoAutomaticSetupExtension::$setupCalls = 0;
		InstalledSetupExtension::$setupCalls = 0;

		$this->createApplication([
			NoAutomaticSetupExtension::class => [],
			InstalledSetupExtension::class => [],
		]);

		$this->assertSame(0, NoAutomaticSetupExtension::$setupCalls);
		$this->assertSame(1, InstalledSetupExtension::$setupCalls);
	}

	public function testDisabledExtensionsAreSkippedByInstall(): void
	{
		DisabledInstallExtension::$installCalls = 0;

		$app = $this->createApplication([
			DisabledInstallExtension::class => [
				'enabled' => fn (Application $app): bool => $app->isDebug(),
			],
		]);

		$this->assertFalse($app->hasExtension(DisabledInstallExtension::class));
		$this->assertSame(0, DisabledInstallExtension::$installCalls);
	}

	/**
	 * @param array<class-string, array<string, mixed>> $extensions
	 */
	private function createApplication(array $extensions): Application
	{
		$this->writeProjectFiles();

		return new Application([
			'project_dir' => $this->projectDir,
			'extensions' => $extensions,
			'debug' => false,
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

use ON\Container\ContainerConfig;
use ON\Discovery\DiscoveryCache;
use ON\Config\AppConfig;
use Tests\ON\Extension\DiscoveryCacheFactory;

$config = new ContainerConfig();
$config->addFactory(DiscoveryCache::class, DiscoveryCacheFactory::class);
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
$config->set('discovery.discoverers', []);
$config->set('discovery.locations', []);

return $config;
PHP
		);
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . '/overnight-extension-test-' . bin2hex(random_bytes(8));
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

final class DiscoveryCacheFactory
{
	public function __invoke(ContainerInterface $container): DiscoveryCache
	{
		return new DiscoveryCache($container);
	}
}

final class NoAutomaticSetupExtension extends AbstractExtension
{
	public static int $setupCalls = 0;

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		return new self();
	}

	public function setup(): void
	{
		self::$setupCalls++;
	}
}

final class InstalledSetupExtension extends AbstractExtension
{
	public static int $setupCalls = 0;

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		return new self();
	}

	public function boot(): void
	{
		$this->when('installed', [$this, 'setup']);
	}

	public function setup(): void
	{
		self::$setupCalls++;
	}
}

final class DisabledInstallExtension extends AbstractExtension
{
	public static int $installCalls = 0;

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		self::$installCalls++;

		return new self();
	}
}
