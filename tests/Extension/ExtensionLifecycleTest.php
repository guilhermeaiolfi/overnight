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
use ON\Extension\ExtensionLifecycle;
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

	public function testConfigSetupRunsAfterAllExtensionsBoot(): void
	{
		BootOrderProbeExtension::$events = [];

		$this->createApplication([
			ConfigExtension::class => [],
			BootOrderProbeExtension::class => [],
		]);

		$this->assertSame([
			'probe.boot',
			'probe.config.setup',
		], BootOrderProbeExtension::$events);
	}

	public function testContainerSetupDefinitionsRegisteredByLaterExtensionAreUsed(): void
	{
		ContainerDefinitionProbeExtension::$containerSetupCalls = 0;

		$app = $this->createApplication([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			ContainerDefinitionProbeExtension::class => [],
		]);

		$this->assertTrue($app->ext('container')->isReady());
		$this->assertSame(1, ContainerDefinitionProbeExtension::$containerSetupCalls);
		$this->assertInstanceOf(ContainerProbeService::class, $app->container->get(ContainerProbeInterface::class));
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

		$lifecycle = new ExtensionLifecycle();
		$extension = new NoAutomaticSetupExtension();
		$extension->setLifecycle($lifecycle);
		$extension->dispatchStateChange('installed');
		$lifecycle->flushDeferredEvents();

		$this->createApplication([
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

	public function testLifecycleGuardReportsTheExtensionThatDoesNotSettle(): void
	{
		$lifecycle = new ExtensionLifecycle();
		$extension = new NeverReadyExtension();
		$extension->setLifecycle($lifecycle);
		$extension->dispatchStateChange('installed');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage(NeverReadyExtension::class);
		$this->expectExceptionMessage('State: installed');
		$this->expectExceptionMessage('empty deferred-event passes');

		$lifecycle->settle([$extension], 1);
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
		$this->dispatchStateChange('ready');
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

final class NeverReadyExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		return new self();
	}
}

final class BootOrderProbeExtension extends AbstractExtension
{
	public static array $events = [];

	public function __construct(private Application $app)
	{
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		return new self($app);
	}

	public function boot(): void
	{
		self::$events[] = 'probe.boot';

		$this->app->ext('config')->when('setup', function () {
			self::$events[] = 'probe.config.setup';
			$this->dispatchStateChange('ready');
		});
	}
}

final class ContainerDefinitionProbeExtension extends AbstractExtension
{
	public static int $containerSetupCalls = 0;

	public function __construct(private Application $app)
	{
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		return new self($app);
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
			self::$containerSetupCalls++;
			$containerConfig = $this->app->config->get(ContainerConfig::class);
			$containerConfig->addAlias(ContainerProbeInterface::class, ContainerProbeService::class);
			$this->dispatchStateChange('ready');
		});
	}
}

interface ContainerProbeInterface
{
}

final class ContainerProbeService implements ContainerProbeInterface
{
}
