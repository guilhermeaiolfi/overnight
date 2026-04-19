<?php

declare(strict_types=1);

namespace Tests\ON\FileRouting;

use FilesystemIterator;
use League\Plates\Engine;
use Laminas\Diactoros\ServerRequest;
use ON\FileRouting\FileRouter;
use ON\FileRouting\FileRoutingConfig;
use ON\FileRouting\Page\MainPage;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\Plates\PlatesRenderer;
use ON\View\View;
use ON\View\ViewConfig;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FileRoutingExtensionTest extends TestCase
{
	private string $projectDir;

	protected function setUp(): void
	{
		$this->projectDir = $this->createProjectDir();
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->projectDir);
	}

	public function testPhpBlockVariablesAreAvailableToTheRenderedFileRouteTemplate(): void
	{
		$pageFile = $this->projectDir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'success.php';
		$this->writeFileRoutePage($pageFile);

		$cachePath = $this->projectDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
		$this->writeDefaultLayout($cachePath);

		$config = new FileRoutingConfig([
			'pagesPath' => $this->projectDir . DIRECTORY_SEPARATOR . 'pages',
			'cachePath' => $cachePath,
		]);
		$viewConfig = $this->createViewConfig($cachePath);
		$engine = new Engine($cachePath, 'php');
		$engine->addFolder('filerouting', $cachePath);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($viewConfig, $engine) {
				if ($class === PlatesRenderer::class) {
					return new PlatesRenderer($viewConfig, $engine, $this->createMock(\ON\Application::class));
				}

				return null;
			});

		$request = new ServerRequest(uri: '/success', method: 'GET');
		$routeResult = (new FileRouter($config, ''))->match($request);

		$this->assertFalse($routeResult->isFailure());

		$request = $request->withAttribute(RouteResult::class, $routeResult);
		$page = new MainPage(
			new View($viewConfig, $container),
			$this->createMock(RouterInterface::class),
			$viewConfig,
			$config
		);

		$result = $page->index($request);

		$this->assertInstanceOf(ViewResult::class, $result);

		$response = $page->successView($result->data, $request);

		$this->assertSame('<div>sucesso absoluto</div>', (string) $response->getBody());
	}

	private function writeFileRoutePage(string $path): void
	{
		if (! is_dir(dirname($path))) {
			mkdir(dirname($path), 0777, true);
		}

		file_put_contents(
			$path,
			<<<'PHP'
<?php

    $ok = "sucesso absoluto";

?>

<div><?php echo $ok; ?></div>
PHP
		);
	}

	private function writeDefaultLayout(string $cachePath): void
	{
		if (! is_dir($cachePath)) {
			mkdir($cachePath, 0777, true);
		}

		file_put_contents(
			$cachePath . 'default.php',
			<<<'PHP'
<?= $this->section('content') ?>
PHP
		);
	}

	private function createViewConfig(string $cachePath): ViewConfig
	{
		return new ViewConfig([
			'templates' => [
				'paths' => [
					$cachePath,
					'filerouting' => $cachePath,
				],
			],
			'formats' => [
				'html' => [
					'default' => 'default',
					'layouts' => [
						'default' => [
							'renderer' => 'plates',
							'sections' => [],
						],
					],
					'renderers' => [
						'plates' => [
							'class' => PlatesRenderer::class,
							'inject' => [],
						],
					],
				],
			],
		]);
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-filerouting-test-' . bin2hex(random_bytes(8));
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
