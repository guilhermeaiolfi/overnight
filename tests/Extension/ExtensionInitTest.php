<?php

declare(strict_types=1);

namespace Tests\ON\Extension;

use Exception;
use FilesystemIterator;
use ON\Application;
use ON\Clockwork\ClockworkExtension;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Discovery\DiscoveryCache;
use ON\Discovery\DiscoveryExtension;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\After;
use ON\Init\Attribute\Before;
use ON\Init\Attribute\InitEvent;
use ON\Init\Attribute\On;
use ON\Init\Init;
use ON\Init\InitContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

final class ExtensionInitTest extends TestCase
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

	public function testContainerBuildsAfterConfigReady(): void
	{
		$app = $this->createApplication([
			ContainerExtension::class => [],
			ConfigExtension::class => [],
		]);

		$this->assertInstanceOf(ContainerConfig::class, $app->ext('container')->getContainer()->get(ContainerConfig::class));
	}

	public function testConfigConfigureListenersRunBeforeConfigFilesLoad(): void
	{
		ConfigSetupProbeExtension::$events = [];

		$this->createApplication([
			ConfigExtension::class => [],
			ConfigSetupProbeExtension::class => [],
		]);

		$this->assertSame([
			'probe.register',
			'probe.config.setup',
		], ConfigSetupProbeExtension::$events);
	}

	public function testContainerSetupDefinitionsRegisteredByLaterExtensionAreUsed(): void
	{
		ContainerDefinitionProbeExtension::$containerSetupCalls = 0;

		$app = $this->createApplication([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			ContainerDefinitionProbeExtension::class => [],
		]);

		$this->assertSame(1, ContainerDefinitionProbeExtension::$containerSetupCalls);
		$this->assertInstanceOf(ContainerProbeService::class, $app->ext('container')->getContainer()->get(ContainerProbeInterface::class));
	}

	public function testDiscoveryStartsAfterContainerRegardlessOfInstallOrder(): void
	{
		$app = $this->createApplication([
			DiscoveryExtension::class => [],
			ContainerExtension::class => [],
			ConfigExtension::class => [],
		]);

		$this->assertInstanceOf(DiscoveryCache::class, $app->ext('container')->getContainer()->get(DiscoveryCache::class));
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

	public function testInstalledExtensionsRegisterWithInitBeforeStart(): void
	{
		InitRegisterProbeExtension::$events = [];

		$app = $this->createApplication([
			InitRegisterProbeExtension::class => [],
		]);

		$app->init()->emit('probe.init', new stdClass());

		$this->assertSame([
			'register',
			'start',
			'listener',
		], InitRegisterProbeExtension::$events);
	}

	public function testMethodsRegisteredDuringRegisterAreAvailableAfterBootstrap(): void
	{
		RegisterMethodProbeExtension::$calls = 0;

		$app = $this->createApplication([
			RegisterMethodProbeExtension::class => [],
		]);

		$this->assertSame('probe-result', $app->probeMethod());
		$this->assertSame(1, RegisterMethodProbeExtension::$calls);
	}

	public function testApplicationExposesNormalizedPaths(): void
	{
		$app = $this->createApplication([]);
		$normalizedProjectDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->projectDir);

		$this->assertSame($normalizedProjectDir, $app->paths->get('project')->getAbsolutePath());
		$this->assertSame($normalizedProjectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache', $app->paths->get('cache')->getAbsolutePath());
		$this->assertSame('var' . DIRECTORY_SEPARATOR . 'cache', $app->paths->get('cache')->getRelativePath());
	}

	public function testDeferredCrossExtensionMethodUsageCanFollowEventsInsteadOfInstallOrder(): void
	{
		RegisterMethodProbeExtension::$calls = 0;

		$this->createApplication([
			MethodConsumerExtension::class => [],
			RegisterMethodProbeExtension::class => [],
		]);

		$this->assertSame(1, RegisterMethodProbeExtension::$calls);
		$this->assertSame(['event', 'method'], MethodConsumerExtension::$events);
	}

	public function testExtensionProfilerIsDisabledWithoutDebugClockwork(): void
	{
		$app = $this->createApplication([
			InitRegisterProbeExtension::class => [],
		], true);

		$this->assertFalse($app->extensionProfiler()->isEnabled());
		$this->assertSame([], $app->extensionProfiler()->samples());
	}

	public function testExtensionProfilerIsDisabledOutsideDebugEvenWithClockwork(): void
	{
		$app = $this->createApplication([
			ClockworkExtension::class => [],
			InitRegisterProbeExtension::class => [],
		]);

		$this->assertFalse($app->extensionProfiler()->isEnabled());
		$this->assertSame([], $app->extensionProfiler()->samples());
	}

	public function testExtensionProfilerRecordsLifecycleWhenDebugClockworkIsInstalled(): void
	{
		$app = $this->createApplication([
			ClockworkExtension::class => [],
			InitRegisterProbeExtension::class => [],
		], true);

		$matches = array_values(array_filter(
			$app->extensionProfiler()->samples(),
			fn (array $sample): bool => $sample['extension'] === InitRegisterProbeExtension::class
		));

		$this->assertTrue($app->extensionProfiler()->isEnabled());
		$this->assertNotEmpty($matches);
	}

	public function testAttributeHooksRunInBeforeOnAfterOrderAndCacheInProduction(): void
	{
		AttributeStageProbeExtension::$events = [];

		$this->createApplication([
			AttributeStageProbeExtension::class => [],
			AttributeEventOwnerExtension::class => [],
		], false);

		$this->assertSame(['before', 'on', 'after'], AttributeStageProbeExtension::$events);

		$cacheFile = $this->projectDir . '/var/cache/app_lifecycle.php';
		$this->assertFileExists($cacheFile);
		$cache = require $cacheFile;
		$this->assertArrayHasKey('hooks', $cache);
		$this->assertCount(3, $cache['hooks'][AttributeStageProbeExtension::class]);
		$this->assertSame([AttributeEventOwnerExtension::class, AttributeStageProbeExtension::class], $cache['order']);

		// Second production boot reuses cached attribute hooks cleanly
		AttributeStageProbeExtension::$events = [];
		$this->createApplication([
			AttributeStageProbeExtension::class => [],
			AttributeEventOwnerExtension::class => [],
		], false);

		$this->assertSame(['before', 'on', 'after'], AttributeStageProbeExtension::$events);
	}

	public function testRequiredInitEventOwnerMissingFailsFast(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage(AttributeEventOwnerExtension::class);

		$this->createApplication([
			AttributeStageProbeExtension::class => [],
		], true);
	}

	public function testOptionalInitEventOwnerMissingIsSilentlyPruned(): void
	{
		OptionalListenerProbeExtension::$calls = 0;
		$extensions = [OptionalListenerProbeExtension::class => []];

		$app = $this->createApplication($extensions);
		$app->init()->emit(new AttributeOwnedBootEvent());
		$this->assertSame(0, OptionalListenerProbeExtension::$calls);
		$app->init()->emit('probe.optional', new stdClass());
		$this->assertSame(1, OptionalListenerProbeExtension::$calls);
		OptionalListenerProbeExtension::$calls = 0;

		$cache = require $this->projectDir . '/var/cache/app_lifecycle.php';
		$this->assertSame([AttributeOwnedBootEvent::class], $cache['prunedEvents']);

		$app = $this->createApplication($extensions);
		$app->init()->emit(new AttributeOwnedBootEvent());
		$this->assertSame(0, OptionalListenerProbeExtension::$calls);

		$this->createApplication($extensions + [AttributeEventOwnerExtension::class => []]);
		$this->assertSame(1, OptionalListenerProbeExtension::$calls);
	}

	public function testLazyInitEventDoesNotCreateCircularDependency(): void
	{
		LazyCycleOwnerExtension::$events = [];

		$app = $this->createApplication([
			LazyCycleOwnerExtension::class => [],
			LazyCycleConsumerExtension::class => [],
		], true);

		$this->assertSame(['owner.boot', 'consumer.on.boot'], LazyCycleOwnerExtension::$events);

		$app->init()->emit(new LazyOnDemandEvent());
		$this->assertSame(['owner.boot', 'consumer.on.boot', 'owner.on.lazy'], LazyCycleOwnerExtension::$events);
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

final class DisabledInstallExtension extends AbstractExtension
{
	public static int $installCalls = 0;

	public function __construct(Application $app, array $options = [])
	{
		self::$installCalls++;
	}
}

final class InitRegisterProbeExtension extends AbstractExtension
{
	public static array $events = [];

	public function register(Init $init): void
	{
		self::$events[] = 'register';
		$init->on('probe.init', function (): void {
			self::$events[] = 'listener';
		});
	}

	public function start(InitContext $context): void
	{
		self::$events[] = 'start';
	}
}

final class ConfigSetupProbeExtension extends AbstractExtension
{
	public static array $events = [];

	public function register(Init $init): void
	{
		self::$events[] = 'probe.register';

		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			self::$events[] = 'probe.config.setup';
		});
	}
}

final class RegisterMethodProbeExtension extends AbstractExtension
{
	public static int $calls = 0;

	public function register(Init $init): void
	{
		$this->app->registerMethod('probeMethod', function (): string {
			self::$calls++;

			return 'probe-result';
		});
	}
}

final class MethodProviderReadyEvent
{
}

final class MethodConsumerExtension extends AbstractExtension
{
	public static array $events = [];

	public function register(Init $init): void
	{
		self::$events = [];

		$init->on(MethodProviderReadyEvent::class, function (): void {
			self::$events[] = 'event';
			$this->app->probeMethod();
			self::$events[] = 'method';
		});
	}

	public function start(InitContext $context): void
	{
		$context->emit(new MethodProviderReadyEvent());
	}
}

final class ContainerDefinitionProbeExtension extends AbstractExtension
{
	public static int $containerSetupCalls = 0;

	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			self::$containerSetupCalls++;
			$event->containerConfig->addAlias(ContainerProbeInterface::class, ContainerProbeService::class);
		});
	}
}

interface ContainerProbeInterface
{
}

final class ContainerProbeService implements ContainerProbeInterface
{
}

#[InitEvent(owner: AttributeEventOwnerExtension::class)]
final class AttributeOwnedBootEvent
{
}

final class AttributeEventOwnerExtension extends AbstractExtension
{
	public function start(InitContext $context): void
	{
		$context->emit(new AttributeOwnedBootEvent());
	}
}

final class AttributeStageProbeExtension extends AbstractExtension
{
	public static array $events = [];

	#[After]
	public function afterBoot(AttributeOwnedBootEvent $event): void
	{
		self::$events[] = 'after';
	}

	#[On]
	public function onBoot(AttributeOwnedBootEvent $event): void
	{
		self::$events[] = 'on';
	}

	#[Before]
	public function beforeBoot(AttributeOwnedBootEvent $event): void
	{
		self::$events[] = 'before';
	}
}

final class OptionalListenerProbeExtension extends AbstractExtension
{
	public static int $calls = 0;

	#[On(AttributeOwnedBootEvent::class, optional: true)]
	#[On('probe.optional', optional: true)]
	public function onOptional(): void
	{
		self::$calls++;
	}
}

#[InitEvent(owner: LazyCycleOwnerExtension::class)]
final class LazyCycleBootEvent
{
}

#[InitEvent(owner: LazyCycleConsumerExtension::class, lazy: true)]
final class LazyOnDemandEvent
{
}

final class LazyCycleOwnerExtension extends AbstractExtension
{
	public static array $events = [];

	public function start(InitContext $context): void
	{
		self::$events[] = 'owner.boot';
		$context->emit(new LazyCycleBootEvent());
	}

	#[On(optional: true)]
	public function onLazy(LazyOnDemandEvent $event): void
	{
		self::$events[] = 'owner.on.lazy';
	}
}

final class LazyCycleConsumerExtension extends AbstractExtension
{
	#[On]
	public function onOwnerBoot(LazyCycleBootEvent $event): void
	{
		LazyCycleOwnerExtension::$events[] = 'consumer.on.boot';
	}
}
