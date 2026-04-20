<?php

declare(strict_types=1);

namespace Tests\ON\View;

use ON\Application;
use ON\Container\ContainerConfig;
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

		$extension->boot();

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

		$extension->boot();

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

		$containerExtension = new class {
			public function when(string $state, callable $callback): void
			{
				if ($state === 'setup') {
					$callback();
				}
			}
		};

		return new class($config, $containerExtension) extends Application {
			public object $config;
			private object $containerExtension;

			public function __construct(object $config, object $containerExtension)
			{
				$this->config = $config;
				$this->containerExtension = $containerExtension;
			}

			public function ext(string $name)
			{
				if ($name !== 'container') {
					throw new \RuntimeException("Unexpected extension {$name}");
				}

				return $this->containerExtension;
			}
		};
	}
}
