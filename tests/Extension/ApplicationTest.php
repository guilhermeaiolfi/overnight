<?php

declare(strict_types=1);

namespace Tests\ON\Extension;

use Exception;
use FilesystemIterator;
use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Runtime\RuntimeInterface;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

#[BackupGlobals(true)]
final class ApplicationTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;
	private ?Application $previousApplication;
	private int $previousErrorReporting;
	private string $previousDisplayErrors;
	private string $previousDisplayStartupErrors;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->previousApplication = Application::$instance;
		$this->previousErrorReporting = error_reporting();
		$this->previousDisplayErrors = ini_get('display_errors');
		$this->previousDisplayStartupErrors = ini_get('display_startup_errors');
		$this->projectDir = sys_get_temp_dir() . '/overnight-app-test-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir, 0755, true);
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = $this->previousApplication;
		error_reporting($this->previousErrorReporting);
		ini_set('display_errors', $this->previousDisplayErrors);
		ini_set('display_startup_errors', $this->previousDisplayStartupErrors);
		$this->removeDirectory($this->projectDir);
	}

	public function testExtensionsFileCanBeLoadedAcrossMultipleApplicationInstances(): void
	{
		$extensionsFile = $this->projectDir . '/extensions.php';
		file_put_contents($extensionsFile, "<?php\nreturn [\n\t" . SampleValidExtension::class . "::class => [],\n];\n");

		$app1 = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => $extensionsFile,
			'debug' => true,
		]);

		$app2 = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => $extensionsFile,
			'debug' => true,
		]);

		$this->assertTrue($app1->hasExtension(SampleValidExtension::class));
		$this->assertTrue($app2->hasExtension(SampleValidExtension::class));
	}

	public function testImplicitMissingDotenvFileDoesNotPreventBoot(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'debug' => true,
		]);

		$this->assertNotNull($app->env);
	}

	public function testBootWithDotenvDisabledHonorsExplicitEnvironment(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'debug' => false,
			'env' => 'staging',
			'disable_dotenv' => true,
		]);

		$this->assertNull($app->env);
		$this->assertSame('staging', $app->getEnvironment());
		$this->assertSame('staging', $_ENV['APP_ENV']);
		$this->assertFalse($app->isDebug());
	}

	public function testHonorsDotenvPathAndOverloadOptions(): void
	{
		$customEnv = $this->projectDir . '/.env.custom';
		file_put_contents($customEnv, "APP_TEST_KEY=from_file\nAPP_ENV=custom_env\n");

		$_ENV['APP_TEST_KEY'] = 'initial';
		$_SERVER['APP_TEST_KEY'] = 'initial';

		$appNoOverload = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'debug' => true,
			'dotenv_path' => $customEnv,
			'dotenv_overload' => false,
		]);

		$this->assertSame('initial', $_ENV['APP_TEST_KEY']);
		$this->assertNotNull($appNoOverload->env);

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'debug' => true,
			'dotenv_path' => $customEnv,
			'dotenv_overload' => true,
		]);

		$this->assertSame('from_file', $_ENV['APP_TEST_KEY']);
	}

	public function testLifecycleCacheIsWrittenAtomicallyInNonDebugMode(): void
	{
		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [SampleValidExtension::class => []],
			'debug' => false,
		]);

		$cacheFile = $this->projectDir . '/var/cache/app_lifecycle.php';
		$this->assertFileExists($cacheFile);

		$cached = require $cacheFile;
		$this->assertIsArray($cached);
		$this->assertSame([SampleValidExtension::class], $cached['order']);
	}

	public function testProfileOptionRecordsExtensionLifecycleWithoutClockwork(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [SampleValidExtension::class => []],
			'debug' => false,
			'profile' => true,
		]);

		$profiler = $app->extensionProfiler();
		$this->assertTrue($profiler->isEnabled());
		$this->assertSame(
			['extension.install', 'extension.register', 'extension.start'],
			array_column($profiler->samples(), 'event')
		);
	}

	public function testNonExtensionClassIsRejectedOutsideDebugMode(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('ExtensionInterface');

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [stdClass::class => []],
			'debug' => false,
		]);
	}

	public function testRunReturnsTheSoleRegisteredRuntimeResult(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [RuntimeExtension::class => []],
			'debug' => true,
		]);

		$this->assertSame(42, $app->run());
	}

	public function testRunThrowsWhenNoRuntimeIsRegistered(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'debug' => true,
		]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('There are no runtimes registered on the application.');

		$app->run();
	}

	public function testDuplicateRuntimeRegistrationNamesBothExtensions(): void
	{
		$first = RuntimeExtension::class;
		$second = ConflictingRuntimeExtension::class;
		$this->expectException(Exception::class);
		$this->expectExceptionMessage(
			"Conflict detected: Runtime \"worker\" was registered by multiple extensions: [{$first}, {$second}]."
		);

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [$first => [], $second => []],
			'debug' => true,
		]);
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

final class SampleValidExtension extends AbstractExtension
{
	public const ID = 'sample_valid';
}

class RuntimeExtension extends AbstractExtension
{
	public function register(Init $init): void
	{
		$this->app->registerRuntime('worker', new class () implements RuntimeInterface {
			public function run(Application $app): int
			{
				return 42;
			}
		});
	}
}

final class ConflictingRuntimeExtension extends RuntimeExtension
{
}
