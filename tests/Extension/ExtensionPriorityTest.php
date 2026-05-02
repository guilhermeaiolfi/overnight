<?php

declare(strict_types=1);

// Dummy events to test inference
namespace Tests\ON\Extension\Base {
    class BaseReadyEvent {}
}

namespace Tests\ON\Extension {

use FilesystemIterator;
use ON\Application;
use ON\Config\Config;
use ON\Config\ConfigExtension;

use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;

use ON\Container\Init\Event\ConfigureContainerEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ExtensionPriorityTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = $this->createProjectDir();
        $this->writeProjectFiles();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		$this->removeDirectory($this->projectDir);
	}

    private function createApplication(array $extensions, bool $debug = true): Application
	{
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

		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . '/overnight-priority-test-' . bin2hex(random_bytes(8));
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

	public function testLaterExtensionInConfigurationOverridesEarlierOne(): void
	{
		$app = $this->createApplication([
            ConfigExtension::class => [],
            ContainerExtension::class => [],
			BaseExtension::class => [],
			OverridingExtension::class => [],
		]);

		$container = $app->ext('container')->getContainer();
		$this->assertEquals('overridden', $container->get('test_key'));
	}

	public function testExtensionOrderInConfigMattersWhenNoDependencies(): void
	{
		// In this case, Overriding is first, so Base should override it
		$app = $this->createApplication([
            ConfigExtension::class => [],
            ContainerExtension::class => [],
			OverridingExtension::class => [],
			BaseExtension::class => [],
		]);

		$container = $app->ext('container')->getContainer();
		$this->assertEquals('base', $container->get('test_key'));
	}

    public function testDependencyOrderShouldPrevailOverConfigOrder(): void
    {
        // DependentExtension depends on BaseExtension via event subscription
        // Even if listed first, it should register AFTER BaseExtension
        // and thus be able to override it.
        $app = $this->createApplication([
            ConfigExtension::class => [],
            ContainerExtension::class => [],
            DependentExtension::class => [],
            BaseExtension::class => [],
        ]);

        $container = $app->ext('container')->getContainer();
        // register() now respects inferred dependencies
        $this->assertEquals('dependent_override', $container->get('test_key'));
    }

    public function testPropertyBasedDefaultsInConfig(): void
    {
        $app = $this->createApplication([
            ConfigExtension::class => [],
        ]);

        $config = $app->ext('config')->get(TestConfig::class);
        $this->assertEquals('default_val', $config->some_key);
        $this->assertEquals('default_val', $config->get('some_key'));
    }

    public function testConfigFileOverridesExtensionDefaults(): void
    {
        file_put_contents($this->projectDir . '/config/test.all.php', "<?php return new Tests\ON\Extension\TestConfig(['some_key' => 'file_val']);");

        $app = $this->createApplication([
            ConfigExtension::class => [],
            ExtensionWithDefaults::class => [],
        ]);

        $config = $app->ext('config')->get(TestConfig::class);
        $this->assertEquals('file_val', $config->some_key);
    }

    public function testRootLevelConfigurationMerging(): void
    {
        file_put_contents($this->projectDir . '/config/root.all.php', "<?php return ['global_key' => 'global_val', 'Tests\ON\Extension\TestConfig' => ['some_key' => 'overridden_from_root']];");

        $app = $this->createApplication([
            ConfigExtension::class => [],
        ]);

        $configs = $app->ext('config')->get();
        $this->assertEquals('global_val', $configs['global_key']);
        
        $config = $app->ext('config')->get(TestConfig::class);
        $this->assertEquals('overridden_from_root', $config->some_key);
    }

    public function testConfigDiskCache(): void
    {
        $cacheFile = $this->projectDir . '/var/cache/config.php';
        $app = $this->createApplication([
            ConfigExtension::class => ['cachePath' => $cacheFile],
            ExtensionWithDefaults::class => [],
        ], false);
        
        // Configuration is loaded/saved in start()
        $this->assertFileExists($cacheFile);
        $content = file_get_contents($cacheFile);
        $this->assertStringContainsString('extension_default', $content);

        // Verify it loads from cache
        $app2 = $this->createApplication([
            ConfigExtension::class => ['cachePath' => $cacheFile],
        ], false);
        $config2 = $app2->ext('config')->get(TestConfig::class);
        $this->assertEquals('extension_default', $config2->some_key);
    }
}

class TestConfig extends Config
{
    public string $some_key = 'default_val';
}

class ExtensionWithDefaults extends AbstractExtension
{
    public function register(Init $init): void
    {
		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
            $config = $event->config->get(TestConfig::class);
            $config->some_key = 'extension_default';
        });
    }
}

class BaseExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
		$init->on(ConfigureContainerEvent::class, function (ConfigureContainerEvent $event): void {
            $event->container->set('test_key', 'base');
        });
    }
}

class OverridingExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
		$init->on(ConfigureContainerEvent::class, function (ConfigureContainerEvent $event): void {
            $event->container->set('test_key', 'overridden');
        });
    }
}

class DependentExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        // Inference: This extension listens to an event in BaseExtension's namespace
        $init->on(\Tests\ON\Extension\Base\BaseReadyEvent::class, fn() => null);

		$init->on(ConfigureContainerEvent::class, function (ConfigureContainerEvent $event): void {
            $event->container->set('test_key', 'dependent_override');
        });
    }
}

} // end namespace Tests\ON\Extension
