<?php

declare(strict_types=1);

namespace ON\View\Config;

class RendererNode extends Node
{
	public function inject(string $name, string $class): self
	{
		$this->set("inject.{$name}", $class);

		return $this;
	}
}
