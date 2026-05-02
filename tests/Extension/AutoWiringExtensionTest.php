<?php

declare(strict_types=1);

namespace Tests\ON\Extension;

use Exception;
use FilesystemIterator;
use ON\Application;
use ON\Extension\AutoWiringExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AutoWiringExtensionTest extends TestCase
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

	public function testDiscoversAndInstallsExtensionsFromConfiguredPath(): void
	{
		$extensionClass = $this->writeModuleExtension('BlogExtension');

		$app = $this->createApplication([
			'scan_path' => 'modules',
		]);

		$this->assertTrue($app->hasExtension($extensionClass));
		$this->assertContains($extensionClass, $app->getInstalledExtensions());
	}

	public function testPassesConfiguredOptions(): void
	{
		$extensionClass = $this->writeModuleExtension('ShopExtension', true);

		$app = $this->createApplication([
			'scan_path' => 'modules',
			'extensions' => [
				$extensionClass => [
					'flag' => 'from-config',
				],
			],
		]);

		$this->assertTrue($app->hasExtension($extensionClass));
		$this->assertSame(['flag' => 'from-config'], $extensionClass::$installedOptions);
	}

	public function testSkipsDisabledExtensions(): void
	{
		$extensionClass = $this->writeModuleExtension('DisabledExtension');

		$app = $this->createApplication([
			'scan_path' => 'modules',
			'extensions' => [
				$extensionClass => [
					'enabled' => false,
				],
			],
		]);

		$this->assertFalse($app->hasExtension($extensionClass));
	}

	public function testAutoWiringExtensionStartsWithoutLifecycleState(): void
	{
		$this->writeModuleExtension('StateExtension');

		$app = $this->createApplication([
			'scan_path' => 'modules',
			'cache' => false,
		]);

		$extension = $app->getExtension(AutoWiringExtension::class);
		$this->assertInstanceOf(AutoWiringExtension::class, $extension);
	}

	public function testAutoCacheWritesToFilesystemWhenDebugIsDisabled(): void
	{
		$extensionClass = $this->writeModuleExtension('CachedExtension');
		$cacheFile = $this->projectDir . '/var/cache/autowiring.php';

		$this->createApplication([
			'scan_path' => 'modules',
			'cache' => 'auto',
		], false);

		$this->assertFileExists($cacheFile);
		$cache = include $cacheFile;

		$this->assertIsArray($cache);
		$this->assertContains($extensionClass, array_keys(reset($cache)));
	}

	public function testAutoCacheDoesNotWriteWhenDebugIsEnabled(): void
	{
		$this->writeModuleExtension('DebugExtension');
		$cacheFile = $this->projectDir . '/var/cache/autowiring.php';

		$this->createApplication([
			'scan_path' => 'modules',
			'cache' => 'auto',
		], true);

		$this->assertFileDoesNotExist($cacheFile);
	}

	public function testCacheTrueWritesEvenWhenDebugIsEnabled(): void
	{
		$this->writeModuleExtension('ForcedCacheExtension');
		$cacheFile = $this->projectDir . '/var/cache/autowiring.php';

		$this->createApplication([
			'scan_path' => 'modules',
			'cache' => true,
		], true);

		$this->assertFileExists($cacheFile);
	}

	public function testCacheFalseNeverWrites(): void
	{
		$this->writeModuleExtension('NoCacheExtension');
		$cacheFile = $this->projectDir . '/var/cache/autowiring.php';

		$this->createApplication([
			'scan_path' => 'modules',
			'cache' => false,
		], false);

		$this->assertFileDoesNotExist($cacheFile);
	}

	public function testExcludesMatchingFiles(): void
	{
		$extensionClass = $this->writeModuleExtension('ExcludedExtension', false, 'modules/Excluded');

		$app = $this->createApplication([
			'scan_path' => 'modules',
			'exclude' => [
				'*/modules/Excluded/*',
			],
		]);

		$this->assertFalse($app->hasExtension($extensionClass));
	}

	public function testThrowsWhenAutoWiredExtensionIsAlsoRegisteredManually(): void
	{
		$extensionClass = $this->writeModuleExtension('DuplicateExtension');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Extension {$extensionClass} is already installed.");

		new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				AutoWiringExtension::class => [
					'scan_path' => 'modules',
				],
				$extensionClass => [],
			],
			'debug' => false,
		]);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function createApplication(array $options, bool $debug = false): Application
	{
		return new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				AutoWiringExtension::class => $options,
			],
			'debug' => $debug,
		]);
	}

	private function writeProjectFiles(): void
	{
		if (! is_dir($this->projectDir . '/config')) {
			mkdir($this->projectDir . '/config', 0777, true);
		}

		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=false\nAPP_ENV=testing\n");
	}

	private function writeModuleExtension(
		string $className,
		bool $recordOptions = false,
		string $directory = 'modules'
	): string {
		$suffix = 'Fixture' . str_replace('.', '', uniqid('', true));
		$namespace = "Tests\\ON\\Extension\\AutoWiringFixtures\\{$suffix}";
		$fqcn = "{$namespace}\\{$className}";
		$staticProperty = $recordOptions ? 'public static array $installedOptions = [];' : '';
		$optionsAssignment = $recordOptions ? 'self::$installedOptions = $options ?? [];' : '';
		$directory = $this->projectDir . '/' . trim($directory, '/');

		if (! is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

		file_put_contents(
			$directory . "/{$className}.php",
			<<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Init\InitContext;

final class {$className} extends AbstractExtension
{
	{$staticProperty}

	public function __construct(Application \$app, array \$options = [])
	{
		{$optionsAssignment}
	}

	public function start(InitContext \$context): void
	{
	}
}
PHP
		);

		return $fqcn;
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . '/overnight-autowiring-test-' . bin2hex(random_bytes(8));
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
