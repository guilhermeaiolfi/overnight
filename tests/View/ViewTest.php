<?php

declare(strict_types=1);

namespace Tests\ON\View;

use Exception;
use Laminas\Diactoros\Response\HtmlResponse;
use ON\Application;
use ON\RequestStack;
use ON\Router\Route;
use ON\View\GenericView;
use ON\View\RendererInterface;
use ON\View\ViewConfig;
use ON\View\ViewInterface;
use ON\View\ViewManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ViewTest extends TestCase
{
	public function testImplementsViewInterface(): void
	{
		$view = $this->createView();

		$this->assertInstanceOf(ViewInterface::class, $view);
	}

	public function testSetAndGetDefaultTemplateName(): void
	{
		$view = $this->createView();

		$this->assertNull($view->getDefaultTemplateName());

		$view->setDefaultTemplateName('app::post-index-success');
		$this->assertSame('app::post-index-success', $view->getDefaultTemplateName());
	}

	public function testRenderDelegatesToRenderer(): void
	{
		$config = $this->createViewConfig();

		$mockRenderer = new class implements RendererInterface {
			public ?array $lastLayoutConfig = null;
			public ?string $lastTemplateName = null;
			public ?array $lastData = null;

			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				$this->lastLayoutConfig = $layoutConfig;
				$this->lastTemplateName = $templateName;
				$this->lastData = $data;
				return '<html>rendered</html>';
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function ($class) use ($mockRenderer) {
				if ($class === 'TestRenderer') {
					return $mockRenderer;
				}
				return null;
			});

		$view = $this->createView($config, $container);

		$result = $view->render(['title' => 'Hello'], 'post/index');

		$this->assertSame('<html>rendered</html>', $result);
		$this->assertSame('post/index', $mockRenderer->lastTemplateName);
		$this->assertSame('Hello', $mockRenderer->lastData['title']);
		$this->assertSame('default', $mockRenderer->lastLayoutConfig['name']);
	}

	public function testRenderUsesDefaultTemplateNameWhenNoneProvided(): void
	{
		$config = $this->createViewConfig();

		$mockRenderer = new class implements RendererInterface {
			public ?string $lastTemplateName = null;
			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				$this->lastTemplateName = $templateName;
				return 'ok';
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mockRenderer);

		$view = $this->createView($config, $container);
		$view->setDefaultTemplateName('app::default-template');

		$view->render([]);

		$this->assertSame('app::default-template', $mockRenderer->lastTemplateName);
	}

	public function testRenderThrowsWhenNoTemplateNameAvailable(): void
	{
		$view = $this->createView();

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No template name');

		$view->render([]);
	}

	public function testRenderResetsDefaultTemplateName(): void
	{
		$config = $this->createViewConfig();

		$mockRenderer = new class implements RendererInterface {
			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				return 'ok';
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mockRenderer);

		$view = $this->createView($config, $container);
		$view->setDefaultTemplateName('first-template');

		$view->render([]);

		$this->assertSame('first-template', $view->getDefaultTemplateName());
	}

	public function testRenderThrowsForInvalidLayout(): void
	{
		$view = $this->createView();

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('no configuration for layout');

		$view->render([], 'template', 'nonexistent');
	}

	public function testRenderInjectsDependencies(): void
	{
		$config = $this->createViewConfig(inject: ['imageManager' => 'ImageManagerClass']);

		$mockRenderer = new class implements RendererInterface {
			public ?array $lastData = null;
			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				$this->lastData = $data;
				return 'ok';
			}
		};

		$fakeImageManager = new \stdClass();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function ($class) use ($mockRenderer, $fakeImageManager) {
				if ($class === 'TestRenderer') return $mockRenderer;
				if ($class === 'ImageManagerClass') return $fakeImageManager;
				return null;
			});

		$view = $this->createView($config, $container);
		$view->render(['title' => 'Test'], 'template');

		$this->assertSame($fakeImageManager, $mockRenderer->lastData['imageManager']);
		$this->assertSame('Test', $mockRenderer->lastData['title']);
	}

	public function testEachInstanceHasOwnState(): void
	{
		$view1 = $this->createView();
		$view2 = $this->createView();

		$view1->setDefaultTemplateName('template-a');
		$view2->setDefaultTemplateName('template-b');

		$this->assertSame('template-a', $view1->getDefaultTemplateName());
		$this->assertSame('template-b', $view2->getDefaultTemplateName());
	}

	public function testRenderInfersRendererFromTemplateExtension(): void
	{
		$contentRenderer = new class implements RendererInterface {
			public array $calls = [];

			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				$this->calls[] = [$params['mode'] ?? 'layout', $templateName];

				return 'LATTE:' . $templateName;
			}
		};

		$outerRenderer = new class implements RendererInterface {
			public ?array $lastParams = null;

			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				$this->lastParams = $params;

				return ($params['sections']['content']['content'] ?? '') . '|' . ($params['sections']['footer']['content'] ?? '');
			}
		};

		$config = new ViewConfig([
			'formats' => [
				'html' => [
					'default' => 'default',
					'layouts' => [
						'default' => [
							'renderer' => 'plates',
							'sections' => [
								'footer' => ['template' => 'app::footer.latte'],
							],
						],
					],
					'renderers' => [
						'plates' => [
							'class' => 'OuterRenderer',
							'extension' => 'phtml',
						],
						'latte' => [
							'class' => 'ContentRenderer',
							'extension' => 'latte',
						],
					],
				],
			],
		]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($outerRenderer, $contentRenderer) {
				return match ($class) {
					'OuterRenderer' => $outerRenderer,
					'ContentRenderer' => $contentRenderer,
					default => null,
				};
			});

		$view = $this->createView($config, $container);
		$result = $view->render([], 'pages::show.latte');

		$this->assertSame('LATTE:pages::show|LATTE:app::footer', $result);
		$this->assertSame(
			[['fragment', 'app::footer'], ['fragment', 'pages::show']],
			$contentRenderer->calls
		);
	}

	public function testExplicitSectionRendererOverrideBeatsTemplateExtension(): void
	{
		$overrideRenderer = new class implements RendererInterface {
			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				return 'OVERRIDE:' . $templateName;
			}
		};

		$outerRenderer = new class implements RendererInterface {
			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				if (($params['mode'] ?? 'layout') === 'fragment') {
					return 'PLATES:' . $templateName;
				}

				return ($params['sections']['content']['content'] ?? '') . '|' . ($params['sections']['footer']['content'] ?? '');
			}
		};

		$config = new ViewConfig([
			'formats' => [
				'html' => [
					'default' => 'default',
					'layouts' => [
						'default' => [
							'renderer' => 'plates',
							'sections' => [
								'footer' => [
									'template' => 'app::footer.phtml',
									'renderer' => 'markdown',
								],
							],
						],
					],
					'renderers' => [
						'plates' => [
							'class' => 'OuterRenderer',
							'extension' => 'phtml',
						],
						'markdown' => [
							'class' => 'OverrideRenderer',
							'extension' => 'md',
						],
					],
				],
			],
		]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($outerRenderer, $overrideRenderer) {
				return match ($class) {
					'OuterRenderer' => $outerRenderer,
					'OverrideRenderer' => $overrideRenderer,
					default => null,
				};
			});

		$view = $this->createView($config, $container);
		$result = $view->render([], 'pages::show');

		$this->assertSame('PLATES:pages::show|OVERRIDE:app::footer.phtml', $result);
	}

	public function testRouteSectionsStillRenderThroughApplication(): void
	{
		$app = new class {
			public function processForward(): HtmlResponse
			{
				return new HtmlResponse('ROUTE');
			}
		};

		$outerRenderer = new class implements RendererInterface {
			public function render($layoutConfig, $templateName, $data, $params = [])
			{
				if (($params['mode'] ?? 'layout') === 'fragment') {
					return 'PLATES:' . $templateName;
				}

				return ($params['sections']['content']['content'] ?? '') . '|' . ($params['sections']['footer']['content'] ?? '');
			}
		};

		$config = new ViewConfig([
			'formats' => [
				'html' => [
					'default' => 'default',
					'layouts' => [
						'default' => [
							'renderer' => 'plates',
							'sections' => [
								'footer' => new Route('/footer', 'TestPage::index', ['GET'], 'footer'),
							],
						],
					],
					'renderers' => [
						'plates' => [
							'class' => 'OuterRenderer',
							'extension' => 'phtml',
						],
					],
				],
			],
		]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($outerRenderer, $app) {
				return match ($class) {
					'OuterRenderer' => $outerRenderer,
					Application::class => $app,
					default => null,
				};
			});

		$view = $this->createView($config, $container);
		$result = $view->render([], 'pages::show');

		$this->assertSame('PLATES:pages::show|ROUTE', $result);
	}


	protected function createViewConfig(array $inject = []): ViewConfig
	{
		$config = new ViewConfig();
		$config->set('formats', [
			'html' => [
				'default' => 'default',
				'layouts' => [
					'default' => [
						'renderer' => 'test',
						'sections' => [],
					],
				],
				'renderers' => [
					'test' => [
						'class' => 'TestRenderer',
						'inject' => $inject,
					],
				],
			],
		]);
		return $config;
	}

	protected function createView(?ViewConfig $config = null, ?ContainerInterface $container = null): GenericView
	{
		$config ??= $this->createViewConfig();
		$container ??= $this->createMock(ContainerInterface::class);

		return new GenericView(new ViewManager($config, $container, new RequestStack()));
	}
}
