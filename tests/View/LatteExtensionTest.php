<?php

declare(strict_types=1);

namespace Tests\ON\View;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Config\ConfigExtension;
use ON\Init\Init;
use ON\FS\PathRegistry;
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

        $app = $this->createApplication($containerConfig, $viewConfig);
		$extension = new LatteExtension($app);
        
        $configExt = new ConfigExtension($app);
        $configExt->set(ViewConfig::class, $viewConfig);
        $configExt->set(ContainerConfig::class, $containerConfig);

		$init = new Init();
		$extension->register($init);
		$init->emit(new ConfigConfigureEvent($configExt));

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

        $app = $this->createApplication($containerConfig, $viewConfig);
		$extension = new LatteExtension($app);

        $configExt = new ConfigExtension($app);
        $configExt->set(ViewConfig::class, $viewConfig);
        $configExt->set(ContainerConfig::class, $containerConfig);

		$init = new Init();
		$extension->register($init);
		$init->emit(new ConfigConfigureEvent($configExt));

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
				$this->paths = new PathRegistry([
					'project' => sys_get_temp_dir(),
				]);
			}
		};
	}
}
