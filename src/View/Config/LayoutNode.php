<?php

declare(strict_types=1);

namespace ON\View\Config;

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
