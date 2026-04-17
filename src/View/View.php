<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use Psr\Container\ContainerInterface;

/**
 * Concrete view implementation.
 *
 * Handles template rendering by resolving layouts, templates, and renderers
 * from ViewConfig. Injected into pages via constructor.
 *
 * Registered as a factory in the container — each page gets a fresh instance.
 */
class View implements ViewInterface
{
	protected ?string $defaultTemplateName = null;

	public function __construct(
		protected ViewConfig $config,
		protected ContainerInterface $container
	) {
	}

	public function render(array $data, ?string $templateName = null, ?string $layoutName = null): string
	{
		if ($templateName === null) {
			$templateName = $this->defaultTemplateName;
			if ($templateName === null) {
				throw new Exception('No template name provided and no default template name set.');
			}
		}

		if ($layoutName === null) {
			$layoutName = $this->config["formats"]["html"]["default"] ?? 'default';
		}

		if (!isset($this->config["formats"]['html']['layouts'][$layoutName])) {
			throw new Exception("There is no configuration for layout name: \"{$layoutName}\"");
		}

		$layoutConfig = $this->config["formats"]['html']['layouts'][$layoutName];

		$rendererName = $layoutConfig['renderer'];
		$rendererConfig = $this->config["formats"]['html']['renderers'][$rendererName];

		$rendererClass = $rendererConfig['class'] ?? '\ON\Renderer';
		$renderer = $this->container->get($rendererClass);

		// Inject configured dependencies into template data
		if (!empty($rendererConfig['inject'])) {
			foreach ($rendererConfig['inject'] as $key => $class) {
				$data[$key] = $this->container->get($class);
			}
		}

		$layoutConfig["name"] = $layoutName;

		// Reset state before rendering so the instance is clean for the next request
		// (safe for long-lived processes like Swoole/RoadRunner)
		$this->reset();
		
		return $renderer->render($layoutConfig, $templateName, $data);
	}

	/**
	 * Clear request-scoped state so this instance can be safely reused.
	 */
	protected function reset(): void
	{
		$this->defaultTemplateName = null;
	}

	public function setDefaultTemplateName(string $templateName): void
	{
		$this->defaultTemplateName = $templateName;
	}

	public function getDefaultTemplateName(): ?string
	{
		return $this->defaultTemplateName;
	}
}
