<?php

declare(strict_types=1);

namespace ON\View\Definition;

class RendererDefinition
{
	public function __construct(
		public string $class,
		public array $injections
	) {

	}
}
