<?php

declare(strict_types=1);

namespace ON\View\Definition;

class OutputFormatDefinition
{
	public function __construct(
		public array $layouts,
		public array $renderers
	) {

	}
}
