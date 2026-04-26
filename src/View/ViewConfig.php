<?php

declare(strict_types=1);

namespace ON\View;

use ON\Config\Config;
use ON\Router\UrlHelper;

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
}
