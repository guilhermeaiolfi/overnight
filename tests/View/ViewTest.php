<?php

declare(strict_types=1);

namespace Tests\ON\View;

use Exception;
use ON\RequestStack;
use ON\View\GenericView;
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

		$mockRenderer = new class {
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

		$mockRenderer = new class {
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

		$mockRenderer = new class {
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

		$mockRenderer = new class {
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
