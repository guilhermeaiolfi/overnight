<?php

declare(strict_types=1);

namespace ON\View\Definition;

class LayoutDefinition
{
	public function __construct(
		public string $renderer,
		public string $template,
		public array $sections = []
	) {

	}
}
