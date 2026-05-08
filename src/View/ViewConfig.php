<?php

declare(strict_types=1);

namespace ON\View;

use ON\Config\Config;
use ON\Router\UrlHelper;
use RuntimeException;

class Node extends Config
{
	public function __construct(array &$items, protected mixed $parent)
	{
		$this->setReference($items);
	}

	public function end(): mixed
	{
		return $this->parent;
	}
}

class RendererNode extends Node
{
	public function inject(string $name, string $class): self
	{
		$this->set("inject.{$name}", $class);

		return $this;
	}
}

class FormatNode extends Node
{
	public function layout(string $name, array $values = []): LayoutNode
	{
		if (! isset($this->items['layouts'][$name])) {
			$this->items['layouts'][$name] = [];
		}

		$layout = new LayoutNode($this->items['layouts'][$name], $this);
		$layout->set($values);

		return $layout;
	}

	public function renderer(string $name, string $class = null): RendererNode
	{
		if (! isset($this->items['renderers'][$name])) {
			$this->items['renderers'][$name] = [];
		}

		$renderer = new RendererNode($this->items['renderers'][$name], $this);
		$renderer->set('class', $class);

		return $renderer;
	}
}

class SectionNode extends Node
{
}

class LayoutNode extends Node
{
	public function section(string $name, $path, $controller, $methods, $route_name): self
	{
		$this->set("sections.{$name}", [
			$path,
			$controller,
			$methods,
			$route_name,
		]);

		return $this;
	}
}

class ViewConfig extends Config
{
	public array $helpers = [
		'url' => UrlHelper::class,
	];

	public function format(string $name): FormatNode
	{
		if (! isset($this->items['formats'][$name])) {
			$this->items['formats'][$name] = [];
		}

		return new FormatNode($this->items['formats'][$name], $this);
	}

	/**
	 * No longer strictly needed for serialization, but kept for API compatibility.
	 */
	public function done(): array
	{
		return $this->all();
	}

	public function getLayoutConfig(string $layoutName): array
	{
		$layoutConfig = $this->get("formats.html.layouts.{$layoutName}");
		if (! is_array($layoutConfig)) {
			throw new RuntimeException(sprintf('There is no configuration for layout name: "%s"', $layoutName));
		}

		$layoutConfig['name'] = $layoutName;

		return $layoutConfig;
	}

	public function getRendererConfig(string $rendererName): array
	{
		$rendererConfig = $this->get("formats.html.renderers.{$rendererName}");
		if (! is_array($rendererConfig)) {
			throw new RuntimeException(sprintf('There is no configuration for renderer name: "%s"', $rendererName));
		}

		return $rendererConfig;
	}

	public function getRendererClass(string $rendererName): string
	{
		$rendererConfig = $this->getRendererConfig($rendererName);

		return $rendererConfig['class'] ?? '\ON\Renderer';
	}

	public function getRendererExtension(string $rendererName): ?string
	{
		$rendererConfig = $this->getRendererConfig($rendererName);
		if (! empty($rendererConfig['extension']) && is_string($rendererConfig['extension'])) {
			return $rendererConfig['extension'];
		}

		return match ($rendererName) {
			'plates' => $this->get('templates.extension', 'phtml'),
			'latte' => $this->get('latte.extension', 'latte'),
			default => null,
		};
	}

	public function getRendererNameFromTemplateExtension(string $templateName): ?string
	{
		$extension = $this->extractTemplateExtension($templateName);
		if ($extension === null) {
			return null;
		}

		foreach (array_keys($this->get('formats.html.renderers', [])) as $rendererName) {
			if ($this->getRendererExtension($rendererName) === $extension) {
				return $rendererName;
			}
		}

		return null;
	}

	public function normalizeTemplateReference(string $templateName): array
	{
		$rendererName = $this->getRendererNameFromTemplateExtension($templateName);
		if ($rendererName === null) {
			return [$templateName, null];
		}

		$extension = $this->extractTemplateExtension($templateName);
		if ($extension === null) {
			return [$templateName, null];
		}

		return [substr($templateName, 0, -strlen('.' . $extension)), $rendererName];
	}

	protected function extractTemplateExtension(string $templateName): ?string
	{
		$templatePath = str_contains($templateName, '::')
			? explode('::', $templateName, 2)[1]
			: $templateName;

		$extension = pathinfo($templatePath, PATHINFO_EXTENSION);

		return $extension !== '' ? $extension : null;
	}
}
