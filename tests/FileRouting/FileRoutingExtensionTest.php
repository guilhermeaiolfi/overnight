<?php

declare(strict_types=1);

namespace Tests\ON\FileRouting;

use FilesystemIterator;
use League\Plates\Engine;
use Laminas\Diactoros\ServerRequest;
use ON\FileRouting\Addon\BreadcrumbsAddon;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRouter;
use ON\FileRouting\FileRoutingConfig;
use ON\FileRouting\Page\MainPage;
use ON\RequestStack;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\RendererInterface;
use ON\View\Plates\PlatesRenderer;
use ON\View\ViewConfig;
use ON\View\ViewManager;
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
		$viewConfig = $this->createViewConfig($cachePath, 'plates');
		$engine = new Engine($cachePath, 'phtml');
		$engine->addFolder('filerouting', $cachePath);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($viewConfig, $engine, $cachePath) {
				if ($class === PlatesRenderer::class) {
					return new PlatesRenderer($viewConfig, $engine, $this->createMock(\ON\Application::class));
				}

				if ($class === TestLatteRenderer::class) {
					return new TestLatteRenderer($cachePath);
				}

				return null;
			});

		$request = new ServerRequest(uri: '/success', method: 'GET');
		$routeResult = (new FileRouter($config, ''))->match($request);

		$this->assertFalse($routeResult->isFailure());

		$request = $request->withAttribute(RouteResult::class, $routeResult);
		$page = new MainPage(
			new ViewManager($viewConfig, $container, new RequestStack()),
			$this->createMock(RouterInterface::class),
			$viewConfig,
			$config
		);

		$result = $page->index($request);

		$this->assertInstanceOf(ViewResult::class, $result);

		$response = $page->successView($result->toArray(), $request);

		$this->assertSame('<div>sucesso absoluto</div>', (string) $response->getBody());
	}

	public function testTemplateLangUsesRendererExtensionFromViewConfig(): void
	{
		$pageFile = $this->projectDir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'success.php';
		$this->writeLatteFileRoutePage($pageFile);

		$cachePath = $this->projectDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
		$this->writeDefaultLayout($cachePath);

		$config = new FileRoutingConfig([
			'pagesPath' => $this->projectDir . DIRECTORY_SEPARATOR . 'pages',
			'cachePath' => $cachePath,
		]);
		$viewConfig = $this->createViewConfig($cachePath, 'latte');
		$engine = new Engine($cachePath, 'phtml');
		$engine->addFolder('filerouting', $cachePath);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($viewConfig, $engine, $cachePath) {
				if ($class === PlatesRenderer::class) {
					return new PlatesRenderer($viewConfig, $engine, $this->createMock(\ON\Application::class));
				}

				if ($class === TestLatteRenderer::class) {
					return new TestLatteRenderer($cachePath);
				}

				return null;
			});

		$request = new ServerRequest(uri: '/success', method: 'GET');
		$routeResult = (new FileRouter($config, ''))->match($request);

		$this->assertFalse($routeResult->isFailure());

		$request = $request->withAttribute(RouteResult::class, $routeResult);
		$page = new MainPage(
			new ViewManager($viewConfig, $container, new RequestStack()),
			$this->createMock(RouterInterface::class),
			$viewConfig,
			$config
		);

		$result = $page->index($request);

		$this->assertInstanceOf(ViewResult::class, $result);
		$this->assertSame('latte', $result->toArray()['_templateLang']);
		$this->assertFileExists($cachePath . 'success.latte');

		$response = $page->successView($result->toArray(), $request);

		$this->assertSame('<div>sucesso absoluto</div>', (string) $response->getBody());
	}

	public function testNestedRoutesWriteControllerTemplateAndMetadataToMatchingCacheFolder(): void
	{
		$pageFile = $this->projectDir . DIRECTORY_SEPARATOR . 'pages'
			. DIRECTORY_SEPARATOR . 'a'
			. DIRECTORY_SEPARATOR . 'b'
			. DIRECTORY_SEPARATOR . 'c.php';
		$this->writeFileRoutePage($pageFile);

		$cachePath = $this->projectDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
		$config = new FileRoutingConfig([
			'pagesPath' => $this->projectDir . DIRECTORY_SEPARATOR . 'pages',
			'cachePath' => $cachePath,
		]);
		$viewConfig = $this->createViewConfig($cachePath, 'plates');

		$cache = new FileRoutingCache($config, $viewConfig);
		$result = $cache->get($pageFile);

		$nestedCachePath = $cachePath . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR;

		$this->assertSame($nestedCachePath . 'c.code.php', $result[0]);
		$this->assertSame($nestedCachePath . 'c.phtml', $result[1]);
		$this->assertNull($result[2]);
		$this->assertFileExists($nestedCachePath . 'c.code.php');
		$this->assertFileExists($nestedCachePath . 'c.phtml');
		$this->assertFileExists($nestedCachePath . 'c.meta.php');

		$metadata = include $nestedCachePath . 'c.meta.php';

		$this->assertSame($nestedCachePath . 'c.code.php', $metadata['controller']);
		$this->assertSame($nestedCachePath . 'c.phtml', $metadata['template']);
	}

	public function testStaleMetadataRegeneratesWhenSourceChanges(): void
	{
		$pageFile = $this->projectDir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'success.php';
		$this->writeFileRoutePage($pageFile);

		$cachePath = $this->projectDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
		$config = new FileRoutingConfig([
			'pagesPath' => $this->projectDir . DIRECTORY_SEPARATOR . 'pages',
			'cachePath' => $cachePath,
		]);
		$viewConfig = $this->createViewConfig($cachePath, 'plates');

		$cache = new FileRoutingCache($config, $viewConfig);
		$cache->get($pageFile);

		file_put_contents(
			$pageFile,
			<<<'PHP'
<?php

    $ok = "cache atualizado";

?>

<div><?php echo $ok; ?></div>
PHP
		);
		touch($pageFile, time() + 2);

		$cache->get($pageFile);

		$this->assertStringContainsString('cache atualizado', file_get_contents($cachePath . 'success.code.php'));
	}

	public function testDashedPageMetadataIsExposedAndRemovedFromCachedController(): void
	{
		$pageFile = $this->projectDir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'ordem-do-dia.php';
		$this->writeFileRoutePageWithMetadata($pageFile);

		$cachePath = $this->projectDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
		$config = new FileRoutingConfig([
			'pagesPath' => $this->projectDir . DIRECTORY_SEPARATOR . 'pages',
			'cachePath' => $cachePath,
			'addons' => [
				BreadcrumbsAddon::class,
			],
		]);
		$viewConfig = $this->createViewConfig($cachePath, 'plates');
		$cache = new FileRoutingCache($config, $viewConfig);

		$result = $cache->get($pageFile);

		$this->assertSame('Ordem do Dia', $result[3]['title']);
		$this->assertSame('auto', $result[3]['breadcrumbs']);
		$this->assertStringNotContainsString('/*---', file_get_contents($cachePath . 'ordem-do-dia.code.php'));

		$engine = new Engine($cachePath, 'phtml');
		$engine->addFolder('filerouting', $cachePath);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($viewConfig, $engine) {
				if ($class === PlatesRenderer::class) {
					return new PlatesRenderer($viewConfig, $engine, $this->createMock(\ON\Application::class));
				}

				return null;
			});

		$request = new ServerRequest(uri: '/ordem-do-dia', method: 'GET');
		$routeResult = (new FileRouter($config, ''))->match($request);
		$request = $request->withAttribute(RouteResult::class, $routeResult);
		$page = new MainPage(
			new ViewManager($viewConfig, $container, new RequestStack()),
			$this->createMock(RouterInterface::class),
			$viewConfig,
			$config
		);

		$viewResult = $page->index($request);
		$data = $viewResult->toArray();

		$this->assertSame('Ordem do Dia', $data['_title']);
		$this->assertSame('Ordem do Dia', $data['_pageMeta']['title']);
		$this->assertSame('Inicio', $data['_breadcrumbs'][0]['label']);
		$this->assertSame('Ordem do Dia', $data['_breadcrumbs'][1]['label']);
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

	private function writeLatteFileRoutePage(string $path): void
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

<template lang="latte">
<div>{$ok}</div>
</template>
PHP
		);
	}

	private function writeFileRoutePageWithMetadata(string $path): void
	{
		if (! is_dir(dirname($path))) {
			mkdir(dirname($path), 0777, true);
		}

		file_put_contents(
			$path,
			<<<'PHP'
<?php
/*---
{
  "title": "Ordem do Dia",
  "breadcrumbs": "auto"
}
---*/

    $ok = "metadata";

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
			$cachePath . 'default.phtml',
			<<<'PHP'
<?= $this->section('content') ?>
PHP
		);
	}

	private function createViewConfig(string $cachePath, string $defaultRenderer): ViewConfig
	{
		return new ViewConfig([
			'templates' => [
				'extension' => 'phtml',
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
							'renderer' => $defaultRenderer,
							'sections' => [],
						],
					],
					'renderers' => [
						'plates' => [
							'class' => PlatesRenderer::class,
							'extension' => 'phtml',
							'inject' => [],
						],
						'latte' => [
							'class' => TestLatteRenderer::class,
							'extension' => 'latte',
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

final class TestLatteRenderer implements RendererInterface
{
	public function __construct(
		private string $cachePath
	) {
	}

	public function render($layout, $template_name, $data, $params = [])
	{
		$templatePath = $this->cachePath . str_replace('filerouting::', '', $template_name) . '.latte';
		$content = file_get_contents($templatePath);

		return trim(str_replace('{$ok}', $data['ok'], $content));
	}
}
