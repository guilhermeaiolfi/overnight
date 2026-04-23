<?php

declare(strict_types=1);

namespace Tests\ON\View;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Container\Init\ContainerInitEvents;
use ON\Container\Init\Event\ConfigureContainerEvent;
use ON\Config\ConfigExtension;
use ON\Init\Init;
use ON\View\Latte\LatteExtension;
use ON\View\Latte\LatteRenderer;
use ON\View\Latte\LatteRendererFactory;
use ON\View\ViewConfig;
use PHPUnit\Framework\TestCase;

final class LatteExtensionTest extends TestCase
{
	public function testConfiguresDefaultLatteTemplateExtension(): void
	{
		$containerConfig = new ContainerConfig();
		$viewConfig = new ViewConfig([
			'formats' => [
				'html' => [
					'renderers' => [
						'latte' => [],
					],
				],
			],
		]);

		$extension = new LatteExtension($this->createApplication($containerConfig, $viewConfig));

		$init = new Init();
		$extension->register($init);
		$init->emit(
			ContainerInitEvents::CONFIGURE,
			new ConfigureContainerEvent($this->createMock(ContainerExtension::class), $this->createMock(ConfigExtension::class), $containerConfig)
		);

		$this->assertSame('latte', $viewConfig->get('latte.extension'));
		$this->assertSame(
			LatteRendererFactory::class,
			$containerConfig->get('definitions.factories.' . LatteRenderer::class)
		);
	}

	public function testDoesNotOverrideConfiguredLatteTemplateExtension(): void
	{
		$containerConfig = new ContainerConfig();
		$viewConfig = new ViewConfig([
			'latte' => [
				'extension' => 'custom-latte',
			],
		]);

		$extension = new LatteExtension($this->createApplication($containerConfig, $viewConfig));

		$init = new Init();
		$extension->register($init);
		$init->emit(
			ContainerInitEvents::CONFIGURE,
			new ConfigureContainerEvent($this->createMock(ContainerExtension::class), $this->createMock(ConfigExtension::class), $containerConfig)
		);

		$this->assertSame('custom-latte', $viewConfig->get('latte.extension'));
	}

	private function createApplication(ContainerConfig $containerConfig, ViewConfig $viewConfig): Application
	{
		$config = new class($containerConfig, $viewConfig) {
			public function __construct(
				private ContainerConfig $containerConfig,
				private ViewConfig $viewConfig
			) {
			}

			public function get(string $className): object
			{
				if ($className === ContainerConfig::class) {
					return $this->containerConfig;
				}

				if ($className === ViewConfig::class) {
					return $this->viewConfig;
				}

				throw new \RuntimeException("Unexpected config {$className}");
			}
		};

		return new class($config) extends Application {
			public object $config;

			public function __construct(object $config)
			{
				$this->config = $config;
			}
		};
	}
}
