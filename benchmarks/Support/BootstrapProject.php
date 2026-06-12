<?php

declare(strict_types=1);

namespace Benchmarks\ON\Support;

use FilesystemIterator;
use ON\Application;
use ON\Cache\CacheConfig;
use ON\Cache\CacheExtension;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Event\EventsExtension;
use ON\Logging\LoggingConfig;
use ON\Logging\LoggingExtension;
use ON\Maintenance\MaintenanceExtension;
use ON\Middleware\PipelineExtension;
use ON\RateLimit\RateLimitExtension;
use ON\Router\RouterExtension;
use ON\Session\SessionConfig;
use ON\Session\SessionExtension;
use ON\Translation\TranslationConfig;
use ON\Translation\TranslationExtension;
use ON\View\ViewConfig;
use ON\View\ViewExtension;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class BootstrapProject
{
	private string $previousCwd;
	private int $productionBootstrapCounter = 0;

	public function __construct(
		private readonly string $projectDir
	) {
		$this->previousCwd = getcwd();
	}

	public static function createBare(bool $debug): self
	{
		$project = new self(self::createProjectDir('bare'));
		$project->writeBaseFiles($debug);

		return $project;
	}

	public static function createCore(bool $debug, bool $enableContainerCache): self
	{
		$project = new self(self::createProjectDir('core'));
		$project->writeBaseFiles($debug);
		$project->writeCoreConfig($enableContainerCache);

		return $project;
	}

	public static function createProduction(bool $debug, bool $enableContainerCache): self
	{
		$project = new self(self::createProjectDir('production'));
		$project->writeBaseFiles($debug);
		$project->writeCoreConfig($enableContainerCache);
		$project->writeProductionConfig();

		return $project;
	}

	public function bootstrapBare(bool $debug): Application
	{
		return $this->bootstrap([], $debug);
	}

	public function bootstrapCore(bool $debug): Application
	{
		return $this->bootstrap([
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			RouterExtension::class => [],
			ViewExtension::class => [],
		], $debug);
	}

	public function bootstrapProduction(bool $debug): Application
	{
		$this->prepareProductionRuntimeFiles();

		return $this->bootstrap($this->productionExtensions(), $debug);
	}

	public function warmCoreCaches(): void
	{
		$app = $this->bootstrapCore(false);
		unset($app);
		$this->resetRuntimeState();
	}

	public function warmProductionCaches(): void
	{
		$this->prepareProductionRuntimeFiles();
		$this->warmBootstrapInSubprocess($this->productionExtensions());
		$this->resetRuntimeState();
	}

	public function destroy(): void
	{
		$this->resetRuntimeState();
		$this->removeDirectory($this->projectDir);
	}

	private function bootstrap(array $extensions, bool $debug): Application
	{
		return new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => $extensions,
			'debug' => $debug,
		]);
	}

	/**
	 * @return array<class-string, array<string, mixed>>
	 */
	private function productionExtensions(): array
	{
		return [
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			EventsExtension::class => [],
			PipelineExtension::class => [],
			RouterExtension::class => [],
			ViewExtension::class => [],
			LoggingExtension::class => [],
			SessionExtension::class => [],
			TranslationExtension::class => [],
			CacheExtension::class => [],
			RateLimitExtension::class => [],
			MaintenanceExtension::class => [],
		];
	}

	private function writeBaseFiles(bool $debug): void
	{
		if (! is_dir($this->projectDir . DIRECTORY_SEPARATOR . 'config')) {
			mkdir($this->projectDir . DIRECTORY_SEPARATOR . 'config', 0777, true);
		}

		$envDebug = $debug ? 'true' : 'false';
		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . '.env',
			"APP_DEBUG={$envDebug}\nAPP_ENV=testing\n"
		);
	}

	private function writeCoreConfig(bool $enableContainerCache): void
	{
		$cacheValue = $enableContainerCache ? 'true' : 'false';

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.all.php',
			<<<PHP
<?php

use ON\Container\ContainerConfig;

\$config = new ContainerConfig();
\$config->set('enable_cache', {$cacheValue});
\$config->set('definition_cache', false);
\$config->set('write_proxies', false);

return \$config;
PHP
		);
	}

	private function writeProductionConfig(): void
	{
		$this->writeProductionAppConfig('config/pipeline.php');

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cache.all.php',
			<<<'PHP'
<?php

use ON\Cache\CacheConfig;

$config = new CacheConfig();
$config->set('enable', true);
$config->set('adapter.namespace', 'bootstrap-bench');

return $config;
PHP
		);

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'logging.all.php',
			<<<'PHP'
<?php

use ON\Logging\LoggingConfig;

$config = new LoggingConfig();
$config->set('default', [
	'type' => 'rotating_file',
	'path' => 'var/log/bootstrap.%date%.log',
	'date_format' => 'Ymd',
]);

return $config;
PHP
		);

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session.all.php',
			<<<'PHP'
<?php

use ON\Session\SessionConfig;

$config = new SessionConfig();
$config->set('options.name', 'overnight-bench');

return $config;
PHP
		);

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'translation.all.php',
			<<<'PHP'
<?php

use ON\Translation\TranslationConfig;

$config = new TranslationConfig();
$config->set('default_locale', 'en_US');
$config->set('default_timezone', 'UTC');

return $config;
PHP
		);

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'view.all.php',
			<<<'PHP'
<?php

use ON\View\ViewConfig;

$config = new ViewConfig();
$config->set('templates.paths.app', ['templates']);
$config->set('templates.extension', 'phtml');

return $config;
PHP
		);

		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'pipeline.php',
			<<<'PHP'
<?php

return static function (\ON\Application $app): void {
};
PHP
		);

		$templatesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'templates';
		if (! is_dir($templatesDir)) {
			mkdir($templatesDir, 0777, true);
		}

		$logDir = $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
		if (! is_dir($logDir)) {
			mkdir($logDir, 0777, true);
		}
	}

	private function prepareProductionRuntimeFiles(): void
	{
		$pipelineRelativePath = 'config/pipeline-runtime-' . $this->productionBootstrapCounter++ . '.php';
		$pipelineAbsolutePath = $this->projectDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pipelineRelativePath);

		file_put_contents(
			$pipelineAbsolutePath,
			<<<'PHP'
<?php

return static function (\ON\Application $app): void {
};
PHP
		);

		$this->writeProductionAppConfig($pipelineRelativePath);
	}

	private function writeProductionAppConfig(string $pipelineRelativePath): void
	{
		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.all.php',
			<<<PHP
<?php

use ON\Config\AppConfig;

\$config = new AppConfig();
\$config->set('discovery.discoverers', []);
\$config->set('discovery.locations', []);
\$config->set('app.pipeline_file', '{$pipelineRelativePath}');

return \$config;
PHP
		);
	}

	private function warmBootstrapInSubprocess(array $extensions): void
	{
		$root = dirname(__DIR__, 2);
		$code = sprintf(
			<<<'PHP'
require %s;
new \ON\Application([
    'paths' => ['project' => %s],
    'extensions' => %s,
    'debug' => false,
]);
PHP,
			var_export($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', true),
			var_export($this->projectDir, true),
			var_export($extensions, true)
		);

		$descriptorSpec = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open(
			[PHP_BINARY, '-r', $code],
			$descriptorSpec,
			$pipes,
			$root,
			null,
			['bypass_shell' => true]
		);

		if (! is_resource($process)) {
			throw new \RuntimeException('Unable to start cache warmup process.');
		}

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		if ($exitCode !== 0) {
			throw new \RuntimeException(trim(($stderr ?: $stdout ?: 'Unknown warmup failure.')));
		}
	}

	private function resetRuntimeState(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
	}

	private static function createProjectDir(string $suffix): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-bench-' . $suffix . '-' . bin2hex(random_bytes(8));
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
