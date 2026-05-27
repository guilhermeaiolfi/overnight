<?php

declare(strict_types=1);

namespace ON\View\Config;

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
